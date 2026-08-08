import 'package:ctms_driver/features/auth/domain/session.dart';

/// A `/auth/login` or `/auth/refresh` payload, in the exact shape
/// `App\Services\Auth\AuthService::tokenResponse()` produces.
Map<String, dynamic> tokenResponseBody({
  String accessToken = 'access-1',
  String refreshToken = 'refresh-1',
  Duration accessTtl = const Duration(hours: 1),
  String role = 'DRIVER',
  bool isActive = true,
  DateTime? now,
}) {
  // Relative to the real clock by default, so a session built here is valid
  // for a manager using the real one. Tests that pin a clock pass [now].
  final issuedAt = now ?? DateTime.now().toUtc();

  return {
    'success': true,
    'message': 'Logged in successfully.',
    'code': 200,
    'data': {
      'access_token': {
        'token': accessToken,
        'token_type': 'Bearer',
        'expires_in': accessTtl.inSeconds,
        'expires_at': issuedAt.add(accessTtl).toIso8601String(),
      },
      'refresh_token': {
        'token': refreshToken,
        'token_type': 'Bearer',
        'expires_in': 1209600,
        'expires_at': issuedAt.add(const Duration(days: 14)).toIso8601String(),
      },
      'user': userJson(role: role, isActive: isActive),
    },
  };
}

/// A `/auth/me` payload.
Map<String, dynamic> meResponseBody({
  String fullName = 'Ravi Kumar',
  String role = 'DRIVER',
  bool isActive = true,
}) {
  return {
    'success': true,
    'message': 'Profile retrieved successfully.',
    'code': 200,
    'data': userJson(fullName: fullName, role: role, isActive: isActive),
  };
}

Map<String, dynamic> userJson({
  String id = 'user-1',
  String fullName = 'Ravi Kumar',
  String role = 'DRIVER',
  bool isActive = true,
}) {
  return {
    'id': id,
    'email': 'ravi@ctms.example',
    'phone_number': '+919999999999',
    'first_name': 'Ravi',
    'last_name': 'Kumar',
    'full_name': fullName,
    'role': role,
    'is_active': isActive,
    'last_login_at': '2026-01-01T05:55:00+00:00',
    'created_at': '2025-06-01T00:00:00+00:00',
    'profile': {
      'id': 'driver-1',
      'license_number': 'TS09-2019-0001234',
      'license_class': 'HEAVY',
      'license_expiry_date': '2027-03-31',
      'status': 'AVAILABLE',
      'total_trips': 412,
    },
  };
}

/// The envelope `App\Support\ApiError::response()` returns.
Map<String, dynamic> errorBody(String message, {Map<String, dynamic>? errors}) => {
      'success': false,
      'message': message,
      'data': null,
      'errors': errors,
    };

Session sessionFixture({
  String accessToken = 'access-1',
  String refreshToken = 'refresh-1',
  DateTime? accessExpiresAt,
}) {
  return Session(
    user: AuthUser.fromJson(userJson()),
    tokens: TokenPair(
      accessToken: accessToken,
      refreshToken: refreshToken,
      accessExpiresAt: accessExpiresAt ?? DateTime.utc(2026, 1, 1, 7),
    ),
  );
}
