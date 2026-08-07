import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config/app_config.dart';

/// Non-secret user preferences.
///
/// Kept out of [SecureStore] deliberately: the keystore is slow enough that
/// reading a theme from it would be visible at launch, and a theme is not a
/// secret.
class AppPreferences extends ChangeNotifier {
  AppPreferences(this._prefs, this._config)
      : _themeMode = _readThemeMode(_prefs),
        _developerMode =
            _config.allowsDeveloperMode && (_prefs.getBool(_kDeveloper) ?? false);

  final SharedPreferences _prefs;
  final AppConfig _config;

  static const _kTheme = 'theme_mode';
  static const _kDeveloper = 'developer_mode';

  ThemeMode _themeMode;
  bool _developerMode;

  /// Defaults to [ThemeMode.system]. A driver going into a tunnel at night
  /// should get the device's answer, not ours.
  ThemeMode get themeMode => _themeMode;

  /// Always false in production, whatever is stored — a build that ships with
  /// the flag already set must not expose diagnostics.
  bool get developerMode => _config.allowsDeveloperMode && _developerMode;

  /// Whether the developer toggle should be shown at all.
  bool get canToggleDeveloperMode => _config.allowsDeveloperMode;

  Future<void> setThemeMode(ThemeMode mode) async {
    if (_themeMode == mode) return;
    _themeMode = mode;
    notifyListeners();
    await _prefs.setString(_kTheme, mode.name);
  }

  Future<void> setDeveloperMode(bool enabled) async {
    if (!_config.allowsDeveloperMode || _developerMode == enabled) return;
    _developerMode = enabled;
    notifyListeners();
    await _prefs.setBool(_kDeveloper, enabled);
  }

  static ThemeMode _readThemeMode(SharedPreferences prefs) {
    return switch (prefs.getString(_kTheme)) {
      'light' => ThemeMode.light,
      'dark' => ThemeMode.dark,
      _ => ThemeMode.system,
    };
  }
}
