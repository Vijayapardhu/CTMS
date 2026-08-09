import 'package:permission_handler/permission_handler.dart' as handler;

/// Capabilities the app asks the operating system for.
enum AppPermission {
  /// Required to run a trip. Without it there is no GPS stream.
  location,

  /// Required for background position while the screen is locked.
  locationAlways,

  /// Required to evidence a failed safety-critical inspection item.
  camera,

  /// Degraded without it: dispatch and approach alerts stop arriving, but the
  /// app still works.
  notifications,
}

enum PermissionStatus { granted, denied, permanentlyDenied, restricted }

/// Permission requests.
///
/// Each is requested at the moment it is first needed, never all at launch,
/// and always with a plain sentence about what cannot happen without it.
abstract interface class PermissionService {
  Future<PermissionStatus> status(AppPermission permission);
  Future<PermissionStatus> request(AppPermission permission);

  /// Opens the system settings page, for a permanently denied permission.
  Future<void> openSettings();
}

/// The real thing, over `permission_handler`.
///
/// Nothing is requested at launch. Each permission is asked for at the moment
/// it is first needed, which is also the only moment the app can explain what
/// cannot happen without it.
class DevicePermissionService implements PermissionService {
  const DevicePermissionService();

  handler.Permission _map(AppPermission permission) => switch (permission) {
        AppPermission.location => handler.Permission.locationWhenInUse,
        AppPermission.locationAlways => handler.Permission.locationAlways,
        AppPermission.camera => handler.Permission.camera,
        AppPermission.notifications => handler.Permission.notification,
      };

  PermissionStatus _translate(handler.PermissionStatus status) => switch (status) {
        handler.PermissionStatus.granted ||
        handler.PermissionStatus.limited ||
        handler.PermissionStatus.provisional =>
          PermissionStatus.granted,
        handler.PermissionStatus.permanentlyDenied =>
          PermissionStatus.permanentlyDenied,
        handler.PermissionStatus.restricted => PermissionStatus.restricted,
        handler.PermissionStatus.denied => PermissionStatus.denied,
      };

  @override
  Future<PermissionStatus> status(AppPermission permission) async =>
      _translate(await _map(permission).status);

  @override
  Future<PermissionStatus> request(AppPermission permission) async =>
      _translate(await _map(permission).request());

  @override
  Future<void> openSettings() => handler.openAppSettings();
}
