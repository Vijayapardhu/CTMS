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
