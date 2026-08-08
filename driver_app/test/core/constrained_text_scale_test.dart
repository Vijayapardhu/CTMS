import 'package:ctms_driver/core/widgets/constrained_text_scale.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_harness.dart';

/// Reads the text scaler in force where it sits.
class _ScaleProbe extends StatelessWidget {
  const _ScaleProbe(this.onScale);

  final void Function(double) onScale;

  @override
  Widget build(BuildContext context) {
    onScale(MediaQuery.textScalerOf(context).scale(10) / 10);
    return const SizedBox.shrink();
  }
}

Widget _atScale(double scale, Widget child) {
  return MediaQuery(
    data: MediaQueryData(textScaler: TextScaler.linear(scale)),
    child: Directionality(textDirection: TextDirection.ltr, child: child),
  );
}

void main() {
  group('ConstrainedTextScale', () {
    testWidgets('caps a control that cannot grow', (tester) async {
      var observed = 0.0;

      await tester.pumpWidget(
        _atScale(2.0, ConstrainedTextScale(child: _ScaleProbe((s) => observed = s))),
      );

      expect(observed, closeTo(1.3, 0.001));
    });

    testWidgets('leaves a smaller setting alone', (tester) async {
      var observed = 0.0;

      await tester.pumpWidget(
        _atScale(1.15, ConstrainedTextScale(child: _ScaleProbe((s) => observed = s))),
      );

      expect(observed, closeTo(1.15, 0.001));
    });

    testWidgets('never shrinks text below the device setting', (tester) async {
      var observed = 0.0;

      await tester.pumpWidget(
        _atScale(0.8, ConstrainedTextScale(child: _ScaleProbe((s) => observed = s))),
      );

      expect(observed, closeTo(1.0, 0.001));
    });

    testWidgets('honours a control-specific ceiling', (tester) async {
      var observed = 0.0;

      await tester.pumpWidget(
        _atScale(
          3.0,
          ConstrainedTextScale(
            maxScaleFactor: 1.6,
            child: _ScaleProbe((s) => observed = s),
          ),
        ),
      );

      expect(observed, closeTo(1.6, 0.001));
    });

    testWidgets('does not leak its cap to a sibling', (tester) async {
      var inside = 0.0;
      var outside = 0.0;

      await tester.pumpWidget(
        _atScale(
          2.0,
          Column(
            children: [
              ConstrainedTextScale(child: _ScaleProbe((s) => inside = s)),
              _ScaleProbe((s) => outside = s),
            ],
          ),
        ),
      );

      expect(inside, closeTo(1.3, 0.001));
      expect(
        outside,
        closeTo(2.0, 0.001),
        reason: 'prose beside a capped control must still scale fully',
      );
    });
  });

  group('application-wide scaling', () {
    testWidgets('the app imposes no global cap', (tester) async {
      final app = await registerTestDependencies(signedIn: true);
      addTearDown(app.dispose);

      tester.platformDispatcher.textScaleFactorTestValue = 2.0;
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

      await pumpApp(tester);

      // Measured, not assumed to equal 2.0: Flutter's system scaler is
      // non-linear and compresses small sizes, so the ratio at a given size is
      // not the platform factor. What matters is that it clears the 1.3 ceiling
      // ConstrainedTextScale imposes — under a global clamp it could not.
      final scaler = MediaQuery.textScalerOf(
        tester.element(find.text('Trip').first),
      );

      expect(
        scaler.scale(16),
        greaterThan(16 * 1.3),
        reason: 'a global clamp would deny a driver with low vision the '
            'setting they chose',
      );
    });

    testWidgets('a capped control still holds its ceiling inside the app',
        (tester) async {
      tester.platformDispatcher.textScaleFactorTestValue = 2.0;
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

      var observed = 0.0;

      await tester.pumpWidget(
        wrapForTest(
          ConstrainedTextScale(child: _ScaleProbe((s) => observed = s)),
        ),
      );
      await settle(tester);

      expect(observed, lessThanOrEqualTo(1.3 + 0.001));
    });
  });
}
