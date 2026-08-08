import 'dart:convert';

import '../../../core/services/logger_service.dart';
import '../../../core/storage/secure_store.dart';
import '../domain/session.dart';

/// Persists the session across restarts.
///
/// Tokens **and** the cached identity go to the encrypted store. The identity
/// is not a secret in the way a token is, but it names a person and carries a
/// licence number, and splitting the two across stores would mean two places
/// to forget to clear on logout.
class SessionStore {
  const SessionStore(this._store, this._logger);

  final SecureStore _store;
  final LoggerService _logger;

  /// Reads the stored session, or null when there is none.
  ///
  /// A partially written session — tokens without a user, or a payload this
  /// version cannot parse — is treated as no session and cleared. Restoring
  /// half a session is worse than asking the driver to sign in again.
  Future<Session?> read() async {
    try {
      final rawTokens = await _store.read(SecureKeys.tokens);
      final rawUser = await _store.read(SecureKeys.user);

      if (rawTokens == null || rawUser == null) {
        // One without the other is a torn write from a previous run.
        if (rawTokens != null || rawUser != null) await clear();
        return null;
      }

      return Session(
        user: AuthUser.fromJson(jsonDecode(rawUser) as Map<String, dynamic>),
        tokens: TokenPair.fromStored(jsonDecode(rawTokens) as Map<String, dynamic>),
      );
    } catch (_) {
      // Never log the payload — it is the token.
      _logger.warn('Stored session unreadable; clearing');
      await clear();
      return null;
    }
  }

  Future<void> write(Session session) async {
    // Tokens first. If the process dies between the two writes, the next
    // launch finds tokens without a user, treats that as no session and asks
    // for a sign-in — recoverable. The reverse would leave an identity the app
    // cannot authenticate.
    await _store.write(SecureKeys.tokens, jsonEncode(session.tokens.toJson()));
    await _store.write(SecureKeys.user, jsonEncode(session.user.toJson()));
  }

  Future<void> clear() async {
    await _store.delete(SecureKeys.tokens);
    await _store.delete(SecureKeys.user);
  }
}
