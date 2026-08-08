import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Encrypted key-value storage for credentials.
///
/// Tokens only. Anything that is not a secret belongs in the database, because
/// secure storage on Android is backed by the keystore and is slow enough that
/// using it for ordinary state would be felt at launch.
abstract interface class SecureStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
  Future<void> clear();
}

class FlutterSecureStore implements SecureStore {
  const FlutterSecureStore(this._storage);

  final FlutterSecureStorage _storage;

  /// flutter_secure_storage 10 encrypts with its own ciphers on Android by
  /// default — `encryptedSharedPreferences` is deprecated and ignored. The
  /// options object stays so the platform configuration has one home if a
  /// setting is ever needed.
  static const androidOptions = AndroidOptions();

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);

  @override
  Future<void> clear() => _storage.deleteAll();
}

/// Keys used by the secure store. Declared here so no two features can invent
/// competing spellings of the same key.
abstract final class SecureKeys {
  /// The access/refresh pair and the access token's expiry, as one JSON blob.
  /// One key rather than three, so a session can never be half-cleared.
  static const tokens = 'session_tokens';

  /// The cached identity from `/auth/me`.
  static const user = 'session_user';
}
