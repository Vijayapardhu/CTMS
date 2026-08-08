/// What the HTTP layer is allowed to ask the session for.
///
/// A narrow seam, deliberately. The API client must be able to attach a token,
/// ask for a refresh and report a dead session — and must not be able to read
/// the driver's identity, start a login, or touch storage. Anything wider and
/// the transport layer starts making authentication decisions.
///
/// It also breaks the cycle: the session needs an HTTP client to refresh, and
/// the client needs the session to attach a token. The session implements this
/// interface and talks to the server through a *separate* unauthenticated
/// client, so a refresh can never recurse through the refresh path.
abstract interface class SessionDelegate {
  /// The access token to attach, or null when there is no session.
  Future<String?> accessToken();

  /// Attempts to exchange the refresh token for a new pair.
  ///
  /// Returns true when a usable access token is available afterwards.
  ///
  /// Implementations must be **single-flight**: five requests that all receive
  /// a 401 at the same moment must produce one refresh, not five. Each refresh
  /// consumes its token server-side, so five racing refreshes invalidate each
  /// other and sign the driver out mid-trip.
  Future<bool> refreshSession();

  /// Called when the session is beyond saving — refresh refused, or the
  /// account has been deactivated. Clears tokens and moves the app to the
  /// expired screen.
  Future<void> onSessionExpired();
}
