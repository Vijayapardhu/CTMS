import 'package:ctms_driver/app/theme/app_theme.dart';
import 'package:ctms_driver/core/design_system/ctms_colors.dart';
import 'package:ctms_driver/core/design_system/tokens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_harness.dart';

void main() {
  group('theme', () {
    test('both brightnesses carry the CTMS colour extension', () {
      expect(AppTheme.light.extension<CtmsColors>(), isNotNull);
      expect(AppTheme.dark.extension<CtmsColors>(), isNotNull);
    });

    test('every button clears the minimum touch target', () {
      for (final theme in [AppTheme.light, AppTheme.dark]) {
        final height = theme.filledButtonTheme.style
            ?.minimumSize
            ?.resolve({})?.height;

        expect(height, greaterThanOrEqualTo(Sizes.touchTarget));
      }
    });

    testWidgets('a theme change repaints the app', (tester) async {
      final app = await registerTestDependencies(signedIn: true);
      addTearDown(app.dispose);

      await pumpApp(tester);

      expect(
        tester.widget<MaterialApp>(find.byType(MaterialApp)).themeMode,
        ThemeMode.system,
      );

      await testPreferences.setThemeMode(ThemeMode.dark);
      await settle(tester);

      expect(
        tester.widget<MaterialApp>(find.byType(MaterialApp)).themeMode,
        ThemeMode.dark,
      );
    });
  });

  group('CtmsColors', () {
    test('light and dark define every semantic role', () {
      for (final colors in [CtmsColors.light, CtmsColors.dark]) {
        expect(colors.positive.a, 1.0);
        expect(colors.caution.a, 1.0);
        expect(colors.critical.a, 1.0);
        expect(colors.emergency.a, 1.0);
      }
    });

    test('emergency is not the same colour as critical', () {
      expect(
        CtmsColors.light.emergency,
        isNot(CtmsColors.light.critical),
        reason: 'if SOS looks like every other warning it stops being '
            'findable in a panic',
      );
    });

    test('stale is visually distinct from live', () {
      expect(CtmsColors.light.staleAccent, isNot(CtmsColors.light.liveAccent));
      expect(CtmsColors.dark.staleAccent, isNot(CtmsColors.dark.liveAccent));
    });

    test('lerp between the two schemes stays a CtmsColors', () {
      final mid = CtmsColors.light.lerp(CtmsColors.dark, 0.5);

      expect(mid, isA<CtmsColors>());
      expect(mid.positive, isNot(CtmsColors.light.positive));
    });

    test('copyWith changes only what it is given', () {
      final changed = CtmsColors.light.copyWith(positive: const Color(0xFF00FF00));

      expect(changed.positive, const Color(0xFF00FF00));
      expect(changed.critical, CtmsColors.light.critical);
    });
  });
}
