import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/design_system/ctms_colors.dart';
import '../../core/design_system/tokens.dart';

/// Light and dark themes for the Driver App.
///
/// Six type sizes, three elevations, three button variants — sized to the
/// twenty components in `docs/driver-app/06-component-library.md` rather than
/// invented ahead of them. Anything not used by a component is not defined.
abstract final class AppTheme {
  static ThemeData get light => _build(Brightness.light);
  static ThemeData get dark => _build(Brightness.dark);

  static ThemeData _build(Brightness brightness) {
    final isLight = brightness == Brightness.light;
    final scheme = _scheme(brightness);
    final text = _textTheme(scheme);

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      textTheme: text,
      scaffoldBackgroundColor: scheme.surface,
      extensions: [isLight ? CtmsColors.light : CtmsColors.dark],

      // Tonal elevation rather than shadows: shadows wash out in sunlight.
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 1,
        centerTitle: false,
        titleTextStyle: text.titleLarge,
        systemOverlayStyle:
            isLight ? SystemUiOverlayStyle.dark : SystemUiOverlayStyle.light,
      ),

      cardTheme: CardThemeData(
        color: scheme.surfaceContainer,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Radii.md),
        ),
      ),

      // Every button meets the 56dp standard height. Prominent actions raise
      // this to 64dp at the call site.
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(Sizes.buttonHeight),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(Radii.md),
          ),
          textStyle: text.labelLarge,
        ),
      ),

      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(Sizes.buttonHeight),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(Radii.md),
          ),
          textStyle: text.labelLarge,
        ),
      ),

      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: const Size(Sizes.touchTarget, Sizes.touchTarget),
          textStyle: text.labelLarge,
        ),
      ),

      navigationBarTheme: NavigationBarThemeData(
        height: 72,
        backgroundColor: scheme.surfaceContainer,
        surfaceTintColor: Colors.transparent,
        indicatorColor: scheme.secondaryContainer,
        // Labels are always shown. A driver must not have to guess an icon.
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        labelTextStyle: WidgetStatePropertyAll(text.labelMedium),
      ),

      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: scheme.surfaceContainerHigh,
        surfaceTintColor: Colors.transparent,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(Radii.lg)),
        ),
        showDragHandle: true,
      ),

      dialogTheme: DialogThemeData(
        backgroundColor: scheme.surfaceContainerHigh,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Radii.lg),
        ),
      ),

      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Radii.sm),
        ),
        contentTextStyle: text.bodyMedium?.copyWith(color: scheme.onInverseSurface),
      ),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: scheme.surfaceContainer,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: Spacing.md,
          vertical: Spacing.md,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(Radii.sm),
          borderSide: BorderSide(color: scheme.outline),
        ),
      ),

      dividerTheme: DividerThemeData(color: scheme.outlineVariant, space: 1),

      // A visible focus ring is required for keyboard and switch access and is
      // never removed.
      focusColor: scheme.primary.withValues(alpha: 0.12),

      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
        },
      ),
    );
  }

  static ColorScheme _scheme(Brightness brightness) {
    return brightness == Brightness.light
        ? const ColorScheme.light(
            primary: Color(0xFF0B57D0),
            onPrimary: Color(0xFFFFFFFF),
            primaryContainer: Color(0xFFD3E3FD),
            onPrimaryContainer: Color(0xFF062E6F),
            secondary: Color(0xFF3B5F8A),
            onSecondary: Color(0xFFFFFFFF),
            secondaryContainer: Color(0xFFD3E3FD),
            onSecondaryContainer: Color(0xFF062E6F),
            error: Color(0xFFB3261E),
            onError: Color(0xFFFFFFFF),
            surface: Color(0xFFFFFFFF),
            onSurface: Color(0xFF1F1F1F),
            onSurfaceVariant: Color(0xFF444746),
            surfaceContainer: Color(0xFFF1F3F4),
            surfaceContainerHigh: Color(0xFFE8EAED),
            outline: Color(0xFFC4C7C5),
            outlineVariant: Color(0xFFE1E3E1),
            inverseSurface: Color(0xFF303030),
            onInverseSurface: Color(0xFFF2F2F2),
          )
        : const ColorScheme.dark(
            primary: Color(0xFFA8C7FA),
            onPrimary: Color(0xFF062E6F),
            primaryContainer: Color(0xFF0842A0),
            onPrimaryContainer: Color(0xFFD3E3FD),
            secondary: Color(0xFF9FC0E8),
            onSecondary: Color(0xFF08305B),
            secondaryContainer: Color(0xFF224874),
            onSecondaryContainer: Color(0xFFD3E3FD),
            error: Color(0xFFFFB4AB),
            onError: Color(0xFF690005),
            surface: Color(0xFF131314),
            onSurface: Color(0xFFE3E3E3),
            onSurfaceVariant: Color(0xFFC4C7C5),
            surfaceContainer: Color(0xFF1E1F20),
            surfaceContainerHigh: Color(0xFF282A2C),
            outline: Color(0xFF444746),
            outlineVariant: Color(0xFF2D2F31),
            inverseSurface: Color(0xFFE3E3E3),
            onInverseSurface: Color(0xFF303030),
          );
  }

  /// Six sizes, mapped onto Material's slots.
  ///
  /// Nothing below 12sp exists: if content does not fit at 12sp, the content is
  /// wrong. Numeric styles use tabular figures so a counter ticking 19 to 20
  /// does not shift horizontally — on a moving vehicle that reads as a glitch.
  static TextTheme _textTheme(ColorScheme scheme) {
    const tabular = [FontFeature.tabularFigures()];

    return TextTheme(
      // `display` — BigNumberDisplay only.
      displayLarge: TextStyle(
        fontSize: 57,
        height: 64 / 57,
        letterSpacing: -0.25,
        fontWeight: FontWeight.w400,
        color: scheme.onSurface,
        fontFeatures: tabular,
      ),
      // `headline` — screen and empty-state titles.
      headlineMedium: TextStyle(
        fontSize: 28,
        height: 36 / 28,
        fontWeight: FontWeight.w400,
        color: scheme.onSurface,
      ),
      // `title` — card titles, registration numbers, sheet titles.
      titleLarge: TextStyle(
        fontSize: 20,
        height: 28 / 20,
        letterSpacing: 0.15,
        fontWeight: FontWeight.w500,
        color: scheme.onSurface,
        fontFeatures: tabular,
      ),
      // `body` — the default for all prose.
      bodyLarge: TextStyle(
        fontSize: 16,
        height: 24 / 16,
        letterSpacing: 0.5,
        fontWeight: FontWeight.w400,
        color: scheme.onSurface,
      ),
      bodyMedium: TextStyle(
        fontSize: 16,
        height: 24 / 16,
        letterSpacing: 0.5,
        fontWeight: FontWeight.w400,
        color: scheme.onSurfaceVariant,
      ),
      // `label` — buttons, chips, field labels.
      labelLarge: TextStyle(
        fontSize: 14,
        height: 20 / 14,
        letterSpacing: 0.1,
        fontWeight: FontWeight.w500,
        color: scheme.onSurface,
      ),
      labelMedium: TextStyle(
        fontSize: 14,
        height: 20 / 14,
        letterSpacing: 0.1,
        fontWeight: FontWeight.w500,
        color: scheme.onSurfaceVariant,
      ),
      // `caption` — timestamps, helper text, "estimated".
      bodySmall: TextStyle(
        fontSize: 12,
        height: 16 / 12,
        letterSpacing: 0.4,
        fontWeight: FontWeight.w400,
        color: scheme.onSurfaceVariant,
        fontFeatures: tabular,
      ),
    );
  }
}
