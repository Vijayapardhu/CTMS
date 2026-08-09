import 'package:ctms_driver/core/design_system/ctms_colors.dart';
import 'package:ctms_driver/core/design_system/tokens.dart';
import 'package:ctms_driver/core/icons/app_icons.dart';
import 'package:ctms_driver/core/widgets/persistent_banner.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_harness.dart';

void main() {
  group('PersistentBanner', () {
    testWidgets('shows its message verbatim', (tester) async {
      await tester.pumpWidget(wrapForTest(
        const Scaffold(
          body: PersistentBanner(
            severity: BannerSeverity.caution,
            message: 'The bus is full (40/40). This student cannot board.',
          ),
        ),
      ));

      expect(
        find.text('The bus is full (40/40). This student cannot board.'),
        findsOneWidget,
        reason: 'a server message is already written for drivers; the banner '
            'is not entitled to reword it',
      );
    });

    testWidgets('carries an icon, so colour is never the only signal',
        (tester) async {
      await tester.pumpWidget(wrapForTest(
        const Scaffold(
          body: PersistentBanner(
            severity: BannerSeverity.caution,
            message: 'Offline',
          ),
        ),
      ));

      expect(
        find.byType(AppIconView),
        findsOneWidget,
        reason: 'around one in twelve male drivers has a colour vision '
            'deficiency',
      );
    });

    testWidgets('each severity takes its own semantic colour', (tester) async {
      for (final (severity, pick) in <(BannerSeverity, Color Function(CtmsColors))>[
        (BannerSeverity.info, (c) => c.info),
        (BannerSeverity.caution, (c) => c.caution),
        (BannerSeverity.critical, (c) => c.critical),
      ]) {
        await tester.pumpWidget(wrapForTest(
          Scaffold(
            body: PersistentBanner(severity: severity, message: 'x'),
          ),
        ));

        final element = tester.element(find.byType(PersistentBanner));
        final material = tester.widget<Material>(
          find.descendant(
            of: find.byType(PersistentBanner),
            matching: find.byType(Material),
          ),
        );

        expect(
          material.color,
          pick(element.ctms),
          reason: '$severity must use its semantic token, not a raw colour',
        );
      }
    });

    testWidgets('an explicit icon overrides the one severity would choose',
        (tester) async {
      await tester.pumpWidget(wrapForTest(
        const Scaffold(
          body: PersistentBanner(
            severity: BannerSeverity.caution,
            icon: AppIcon.offline,
            message: 'Offline',
          ),
        ),
      ));

      expect(
        tester.widget<AppIconView>(find.byType(AppIconView)).icon,
        AppIcon.offline,
        reason: 'the registry has a glyph that says why, not merely how badly',
      );
    });

    testWidgets('is not dismissible by default', (tester) async {
      await tester.pumpWidget(wrapForTest(
        const Scaffold(
          body: PersistentBanner(
            severity: BannerSeverity.caution,
            message: 'Offline',
          ),
        ),
      ));

      expect(
        find.byType(IconButton),
        findsNothing,
        reason: 'dismissing a banner does not resolve the condition it '
            'reports, so the default must not offer to',
      );
    });

    testWidgets('offers a close control only when told it may', (tester) async {
      var dismissed = 0;

      await tester.pumpWidget(wrapForTest(
        Scaffold(
          body: PersistentBanner(
            severity: BannerSeverity.info,
            message: 'Replacement dispatched',
            dismissible: true,
            onDismiss: () => dismissed++,
          ),
        ),
      ));

      await tester.tap(find.byType(IconButton));
      await tester.pump();

      expect(dismissed, 1);
    });

    testWidgets('renders an action beside the message', (tester) async {
      var tapped = 0;

      await tester.pumpWidget(wrapForTest(
        Scaffold(
          body: PersistentBanner(
            severity: BannerSeverity.critical,
            message: 'Two changes were rejected',
            action: TextButton(
              onPressed: () => tapped++,
              child: const Text('Review'),
            ),
          ),
        ),
      ));

      await tester.tap(find.text('Review'));
      await tester.pump();

      expect(tapped, 1);
    });
  });

  group('AnimatedPersistentBanner', () {
    const banner = PersistentBanner(
      severity: BannerSeverity.caution,
      message: 'Offline',
    );

    testWidgets('takes no height while hidden', (tester) async {
      await tester.pumpWidget(wrapForTest(
        Scaffold(
          body: Column(
            children: const [
              AnimatedPersistentBanner(visible: false, banner: banner),
              Expanded(child: Placeholder()),
            ],
          ),
        ),
      ));

      expect(find.text('Offline'), findsNothing);
      expect(
        tester.getSize(find.byType(AnimatedPersistentBanner)).height,
        0,
        reason: 'a hidden banner must not reserve a strip of the screen',
      );
    });

    testWidgets('pushes the content below it down rather than covering it',
        (tester) async {
      Widget shell(bool visible) => wrapForTest(
            Scaffold(
              body: Column(
                children: [
                  AnimatedPersistentBanner(visible: visible, banner: banner),
                  const Expanded(child: Placeholder()),
                ],
              ),
            ),
          );

      await tester.pumpWidget(shell(false));
      final before = tester.getTopLeft(find.byType(Placeholder)).dy;

      await tester.pumpWidget(shell(true));
      await tester.pumpAndSettle();
      final after = tester.getTopLeft(find.byType(Placeholder)).dy;

      expect(
        after,
        greaterThan(before),
        reason: 'a banner that overlays covers the control the driver was '
            'reaching for',
      );
    });

    testWidgets('expands over the specified 200ms rather than appearing',
        (tester) async {
      Widget shell(bool visible) => wrapForTest(
            Scaffold(
              body: Column(
                children: [
                  AnimatedPersistentBanner(visible: visible, banner: banner),
                  const Expanded(child: Placeholder()),
                ],
              ),
            ),
          );

      await tester.pumpWidget(shell(false));
      await tester.pumpWidget(shell(true));

      await tester.pump();
      final atStart = tester.getSize(find.byType(AnimatedPersistentBanner)).height;

      await tester.pump(const Duration(milliseconds: 100));
      final midway = tester.getSize(find.byType(AnimatedPersistentBanner)).height;

      await tester.pumpAndSettle();
      final settled = tester.getSize(find.byType(AnimatedPersistentBanner)).height;

      expect(atStart, lessThan(settled));
      expect(midway, greaterThan(atStart));
      expect(midway, lessThan(settled));
      expect(Motion.bannerIn, const Duration(milliseconds: 200));
    });
  });
}
