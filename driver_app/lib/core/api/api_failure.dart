/// A failure the UI can act on.
///
/// Every variant carries [message] — the server's own wording. The backend
/// writes its refusals for drivers ("The bus is full (40/40). This student
/// cannot board."), so the UI shows them verbatim rather than paraphrasing.
sealed class ApiFailure implements Exception {
  const ApiFailure(this.message);

  final String message;

  @override
  String toString() => '$runtimeType: $message';
}

/// No connectivity, or the server could not be reached. The action should be
/// queued rather than reported as an error.
final class NetworkFailure extends ApiFailure {
  const NetworkFailure([super.message = 'The CTMS server could not be reached.']);
}

/// 401. Not authenticated — or the account has been deactivated, which is
/// indistinguishable from the client and must not be described as "expired".
final class AuthFailure extends ApiFailure {
  const AuthFailure([super.message = 'You have been signed out.']);
}

/// 403. Authenticated but not permitted. Never retried: a driver seeing this
/// has hit a control that should not have been rendered.
final class ForbiddenFailure extends ApiFailure {
  const ForbiddenFailure([super.message = 'You do not have permission.']);
}

/// 404.
final class NotFoundFailure extends ApiFailure {
  const NotFoundFailure([super.message = 'Not found.']);
}

/// 409. A business rule refused.
///
/// The most important failure in this app: every safety rule declines through
/// it. [context] carries the rule's own detail, such as `{capacity, occupied}`
/// or the `reasons` array from a refused trip start.
final class ConflictFailure extends ApiFailure {
  const ConflictFailure(super.message, {this.context = const {}});

  final Map<String, dynamic> context;

  /// Every blocking reason, when the server returned a list. The trip-start
  /// gate reports all of them at once rather than the first, and the UI is
  /// expected to render the whole array.
  List<String> get reasons {
    final raw = context['reasons'];
    return raw is List ? raw.map((e) => e.toString()).toList() : const [];
  }
}

/// 422. Field-level validation.
final class ValidationFailure extends ApiFailure {
  const ValidationFailure(super.message, {this.fieldErrors = const {}});

  final Map<String, List<String>> fieldErrors;

  String? firstFor(String field) => fieldErrors[field]?.firstOrNull;
}

/// 429. Rate limited. Never surfaced during a running trip; the caller backs
/// off silently.
final class RateLimitFailure extends ApiFailure {
  const RateLimitFailure([super.message = 'Too many requests.']);
}

/// 5xx. The body carries no internals by design, so the message is generic.
final class ServerFailure extends ApiFailure {
  const ServerFailure([super.message = 'Something went wrong on our side.']);
}
