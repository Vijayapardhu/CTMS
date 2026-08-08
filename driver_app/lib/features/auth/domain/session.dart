import 'package:equatable/equatable.dart';

/// The signed-in driver, as `GET /auth/me` describes them.
///
/// Only the fields this app renders. `phone_number` is here because the Me
/// screen shows it; nothing else from the payload is kept, because a field
/// stored is a field that has to be kept correct.
class AuthUser extends Equatable {
  const AuthUser({
    required this.id,
    required this.email,
    required this.fullName,
    required this.role,
    required this.isActive,
    this.phoneNumber,
    this.profile,
  });

  final String id;
  final String email;
  final String fullName;
  final String role;
  final bool isActive;
  final String? phoneNumber;
  final DriverProfile? profile;

  /// The canonical role for this app. Compared as a constant, never as a
  /// lower-cased literal — the backend's enum values are uppercase and a
  /// case-normalising comparison hides the day one of them changes.
  static const driverRole = 'DRIVER';

  bool get isDriver => role == driverRole;

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    return AuthUser(
      id: json['id'] as String,
      email: json['email'] as String,
      fullName: json['full_name'] as String? ?? '',
      role: json['role'] as String? ?? '',
      isActive: json['is_active'] as bool? ?? false,
      phoneNumber: json['phone_number'] as String?,
      profile: json['profile'] is Map<String, dynamic>
          ? DriverProfile.fromJson(json['profile'] as Map<String, dynamic>)
          : null,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'email': email,
        'full_name': fullName,
        'role': role,
        'is_active': isActive,
        'phone_number': phoneNumber,
        'profile': profile?.toJson(),
      };

  @override
  List<Object?> get props => [id, email, fullName, role, isActive, phoneNumber, profile];
}

/// The driver record attached to the user.
///
/// `license_expiry_date` is kept because an expired licence is one of the
/// reasons `POST /trips/{id}/start` returns 409, and the Me screen has to be
/// able to explain that before the driver is standing at the bus.
class DriverProfile extends Equatable {
  const DriverProfile({
    required this.id,
    required this.licenseNumber,
    required this.status,
    this.licenseClass,
    this.licenseExpiryDate,
    this.totalTrips,
  });

  final String id;
  final String licenseNumber;
  final String status;
  final String? licenseClass;
  final DateTime? licenseExpiryDate;
  final int? totalTrips;

  factory DriverProfile.fromJson(Map<String, dynamic> json) {
    return DriverProfile(
      id: json['id'] as String,
      licenseNumber: json['license_number'] as String? ?? '',
      status: json['status'] as String? ?? '',
      licenseClass: json['license_class'] as String?,
      licenseExpiryDate: _date(json['license_expiry_date']),
      totalTrips: json['total_trips'] as int?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'license_number': licenseNumber,
        'status': status,
        'license_class': licenseClass,
        'license_expiry_date': licenseExpiryDate?.toIso8601String(),
        'total_trips': totalTrips,
      };

  static DateTime? _date(Object? value) =>
      value is String ? DateTime.tryParse(value) : null;

  @override
  List<Object?> get props =>
      [id, licenseNumber, status, licenseClass, licenseExpiryDate, totalTrips];
}

/// An access/refresh pair with the access token's expiry.
///
/// The expiry is stored so the app can refresh *before* a request fails rather
/// than after. Waiting for the 401 costs a round trip at exactly the moment a
/// driver is trying to record a boarding.
class TokenPair extends Equatable {
  const TokenPair({
    required this.accessToken,
    required this.refreshToken,
    required this.accessExpiresAt,
  });

  final String accessToken;
  final String refreshToken;
  final DateTime accessExpiresAt;

  /// Treated as expired a minute early. Clock skew between a handset and the
  /// server is real, and a token that expires in transit reads to the driver
  /// as a random failure.
  static const skew = Duration(minutes: 1);

  bool isExpiredAt(DateTime now) =>
      !now.isBefore(accessExpiresAt.subtract(skew));

  /// Parses the `{access_token: {...}, refresh_token: {...}}` shape that
  /// `/auth/login` and `/auth/refresh` both return.
  factory TokenPair.fromJson(Map<String, dynamic> json) {
    final access = json['access_token'] as Map<String, dynamic>;
    final refresh = json['refresh_token'] as Map<String, dynamic>;

    return TokenPair(
      accessToken: access['token'] as String,
      refreshToken: refresh['token'] as String,
      accessExpiresAt: _expiry(access),
    );
  }

  /// Prefers the absolute `expires_at`; falls back to `expires_in` seconds.
  /// A handset whose clock is wrong makes the relative value the safer of the
  /// two, but only the absolute one survives the app being killed and
  /// restarted an hour later.
  static DateTime _expiry(Map<String, dynamic> token) {
    final at = token['expires_at'];
    if (at is String) {
      final parsed = DateTime.tryParse(at);
      if (parsed != null) return parsed.toUtc();
    }

    final seconds = token['expires_in'];
    if (seconds is int) {
      return DateTime.now().toUtc().add(Duration(seconds: seconds));
    }

    // Neither field present: treat as already expired rather than as valid
    // forever. A wrong "valid" is a session that never recovers.
    return DateTime.now().toUtc();
  }

  Map<String, dynamic> toJson() => {
        'access_token': accessToken,
        'refresh_token': refreshToken,
        'access_expires_at': accessExpiresAt.toIso8601String(),
      };

  factory TokenPair.fromStored(Map<String, dynamic> json) => TokenPair(
        accessToken: json['access_token'] as String,
        refreshToken: json['refresh_token'] as String,
        accessExpiresAt:
            DateTime.parse(json['access_expires_at'] as String).toUtc(),
      );

  @override
  List<Object?> get props => [accessToken, refreshToken, accessExpiresAt];

  /// Never prints either token.
  @override
  String toString() => 'TokenPair(expires: $accessExpiresAt)';
}

/// A live session: who the driver is, and what proves it.
class Session extends Equatable {
  const Session({required this.user, required this.tokens});

  final AuthUser user;
  final TokenPair tokens;

  Session copyWith({AuthUser? user, TokenPair? tokens}) =>
      Session(user: user ?? this.user, tokens: tokens ?? this.tokens);

  @override
  List<Object?> get props => [user, tokens];
}
