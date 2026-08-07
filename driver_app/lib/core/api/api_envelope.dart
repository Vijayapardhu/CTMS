import 'api_failure.dart';

/// The response shape every CTMS endpoint uses.
///
/// `{ success, message, data, code }`, plus `pagination` on list endpoints.
/// Unwrapped in the data layer so nothing above it ever sees the envelope.
class ApiEnvelope<T> {
  const ApiEnvelope({
    required this.success,
    required this.message,
    required this.code,
    this.data,
    this.pagination,
  });

  final bool success;
  final String message;
  final int code;
  final T? data;
  final Pagination? pagination;

  static ApiEnvelope<Map<String, dynamic>> fromJson(Map<String, dynamic> json) {
    return ApiEnvelope<Map<String, dynamic>>(
      success: json['success'] as bool? ?? false,
      message: json['message'] as String? ?? '',
      code: json['code'] as int? ?? 0,
      data: json['data'] is Map<String, dynamic>
          ? json['data'] as Map<String, dynamic>
          : null,
      pagination: json['pagination'] is Map<String, dynamic>
          ? Pagination.fromJson(json['pagination'] as Map<String, dynamic>)
          : null,
    );
  }

  /// Builds the failure for an unsuccessful response.
  ///
  /// The mapping is deliberately explicit rather than a range check: 409 and
  /// 422 carry different payloads and the UI treats them differently.
  static ApiFailure failureFrom(int status, Map<String, dynamic>? body) {
    final message = body?['message'] as String?;
    final errors = body?['errors'];

    return switch (status) {
      401 => AuthFailure(message ?? 'You have been signed out.'),
      403 => ForbiddenFailure(message ?? 'You do not have permission.'),
      404 => NotFoundFailure(message ?? 'Not found.'),
      409 => ConflictFailure(
          message ?? 'That is not possible right now.',
          context: errors is Map<String, dynamic> ? errors : const {},
        ),
      422 => ValidationFailure(
          message ?? 'Please check what you entered.',
          fieldErrors: _fieldErrors(errors),
        ),
      429 => RateLimitFailure(message ?? 'Too many requests.'),
      >= 500 => ServerFailure(message ?? 'Something went wrong on our side.'),
      _ => ServerFailure(message ?? 'Unexpected response ($status).'),
    };
  }

  static Map<String, List<String>> _fieldErrors(Object? errors) {
    if (errors is! Map<String, dynamic>) return const {};

    return errors.map(
      (field, value) => MapEntry(
        field,
        value is List ? value.map((e) => e.toString()).toList() : [value.toString()],
      ),
    );
  }
}

/// Pagination metadata. `per_page` is capped at 100 server-side.
class Pagination {
  const Pagination({
    required this.total,
    required this.perPage,
    required this.currentPage,
    required this.lastPage,
  });

  final int total;
  final int perPage;
  final int currentPage;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;

  factory Pagination.fromJson(Map<String, dynamic> json) => Pagination(
        total: json['total'] as int? ?? 0,
        perPage: json['per_page'] as int? ?? 0,
        currentPage: json['current_page'] as int? ?? 1,
        lastPage: json['last_page'] as int? ?? 1,
      );
}
