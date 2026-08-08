import '../../../core/api/api_client.dart';
import '../domain/session.dart';

/// The six authentication endpoints, and nothing else.
///
/// Given the **unauthenticated** [ApiClient]. Every call that needs a bearer
/// passes it explicitly, so a refresh can never recurse through the refresh
/// path and a failed `/auth/me` can never trigger a second refresh behind the
/// first one's back.
class AuthApi {
  const AuthApi(this._client);

  final ApiClient _client;

  /// `POST /auth/login`. Throttled 5/min per email, 20/min per IP.
  ///
  /// A 401 here means bad credentials **or** a deactivated account, and the
  /// backend deliberately does not distinguish the first from an unknown
  /// address. UI copy must not imply otherwise.
  Future<Session> login({required String email, required String password}) async {
    final body = await _client.post(
      '/auth/login',
      body: {'email': email, 'password': password},
    );

    return _session(body);
  }

  /// `POST /auth/refresh`. Consumes the presented refresh token server-side,
  /// so the returned pair must be stored before anything else uses the old one.
  Future<Session> refresh(String refreshToken) async {
    final body = await _client.post(
      '/auth/refresh',
      body: {'refresh_token': refreshToken},
    );

    return _session(body);
  }

  /// `GET /auth/me`. The identity check that turns stored tokens into a
  /// confirmed session.
  Future<AuthUser> me(String accessToken) async {
    final body = await _client.get('/auth/me', bearer: accessToken);

    return AuthUser.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// `POST /auth/logout`. Revokes this device's access token.
  Future<void> logout(String accessToken) =>
      _client.post('/auth/logout', bearer: accessToken);

  /// `POST /auth/logout-all`. Revokes every token the driver holds, everywhere.
  Future<void> logoutEverywhere(String accessToken) =>
      _client.post('/auth/logout-all', bearer: accessToken);

  Session _session(Map<String, dynamic> body) {
    final data = body['data'] as Map<String, dynamic>;

    return Session(
      user: AuthUser.fromJson(data['user'] as Map<String, dynamic>),
      tokens: TokenPair.fromJson(data),
    );
  }
}
