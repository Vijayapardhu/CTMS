import 'package:dio/dio.dart';

import '../services/logger_service.dart';
import 'api_envelope.dart';
import 'api_failure.dart';
import 'session_delegate.dart';

/// The HTTP boundary.
///
/// Three responsibilities and no more: attach the right headers, recover once
/// from an expired token, and turn a response into either a payload or an
/// [ApiFailure]. Business rules live in the backend; retry policy lives here;
/// everything else lives above.
///
/// Constructed twice by design. The instance features use carries a
/// [SessionDelegate]; the one the authentication layer uses carries none, so
/// `POST /auth/refresh` cannot trigger a refresh of its own.
class ApiClient {
  ApiClient({
    required String baseUrl,
    required LoggerService logger,
    SessionDelegate? session,
    Dio? dio,
    List<Duration> retryDelays = _defaultRetryDelays,
  })  : _logger = logger,
        _session = session,
        _dio = dio ?? Dio() {
    _dio.options = BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 20),
      headers: {'Accept': 'application/json'},
      // Never throw on status: the envelope decides what is a failure, so that
      // a 409 arrives as a typed refusal rather than a DioException the UI has
      // to unpick.
      validateStatus: (_) => true,
    );

    _dio.interceptors.add(_EnvelopeInterceptor());
    _retryDelays = retryDelays;
  }

  /// Back-off between retries of a transient failure. One retry after a second,
  /// one after three — long enough to ride out a cell handover, short enough
  /// that a driver waiting on a boarding does not give up and tap again.
  static const _defaultRetryDelays = [Duration(seconds: 1), Duration(seconds: 3)];

  final Dio _dio;
  final LoggerService _logger;
  final SessionDelegate? _session;

  late final List<Duration> _retryDelays;

  /// Exposed for configuration and for tests that need to install an adapter.
  Dio get dio => _dio;

  /// Whether this instance participates in the session at all.
  bool get isAuthenticated => _session != null;

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
    String? bearer,
  }) {
    return _send(
      (options) => _dio.get<dynamic>(path, queryParameters: query, options: options),
      bearer: bearer,
    );
  }

  Future<Map<String, dynamic>> post(String path, {Object? body, String? bearer}) {
    return _send(
      (options) => _dio.post<dynamic>(path, data: body, options: options),
      bearer: bearer,
    );
  }

  Future<Map<String, dynamic>> put(String path, {Object? body, String? bearer}) {
    return _send(
      (options) => _dio.put<dynamic>(path, data: body, options: options),
      bearer: bearer,
    );
  }

  Future<Map<String, dynamic>> patch(String path, {Object? body, String? bearer}) {
    return _send(
      (options) => _dio.patch<dynamic>(path, data: body, options: options),
      bearer: bearer,
    );
  }

  Future<Map<String, dynamic>> delete(String path, {String? bearer}) {
    return _send(
      (options) => _dio.delete<dynamic>(path, options: options),
      bearer: bearer,
    );
  }

  /// Issues the request, retries what is transient, and recovers once from a
  /// dead token.
  ///
  /// The whole policy lives here rather than in a Dio interceptor because
  /// `validateStatus` accepts every status: a 500 arrives as an ordinary
  /// response, so `onError` never sees it and an error interceptor could only
  /// ever have retried connection failures.
  ///
  /// Two rules, both deliberate:
  ///
  /// * **Only 5xx and connection failures are retried.** A 4xx is an answer.
  ///   Retrying a 409 is how a bus gets double-boarded.
  /// * **A 401 is recovered from once.** A 401 that survives a successful
  ///   refresh is not an expiry — it is a deactivated account — and looping on
  ///   it would hammer the server while telling the driver nothing.
  Future<Map<String, dynamic>> _send(
    Future<Response<dynamic>> Function(Options options) request, {
    String? bearer,
  }) async {
    var attempt = 0;
    var refreshed = false;

    while (true) {
      final token = bearer ?? await _session?.accessToken();

      final Response<dynamic> response;
      try {
        response = await request(
          Options(headers: token == null ? null : {'Authorization': 'Bearer $token'}),
        );
      } on DioException catch (e) {
        if (attempt < _retryDelays.length) {
          await Future<void>.delayed(_retryDelays[attempt]);
          attempt++;
          continue;
        }

        _logger.warn('Request failed', context: {'path': e.requestOptions.path});
        throw const NetworkFailure();
      }

      final status = response.statusCode ?? 0;
      final body = response.data is Map<String, dynamic>
          ? response.data as Map<String, dynamic>
          : <String, dynamic>{};

      if (status >= 200 && status < 300) return body;

      // An explicit bearer means the caller chose that identity; refreshing
      // behind its back would send a different one than it asked for.
      if (status == 401 && !refreshed && bearer == null && _session != null) {
        refreshed = true;

        if (await _session.refreshSession()) continue;

        await _session.onSessionExpired();
        throw ApiEnvelope.failureFrom(status, body);
      }

      if (status >= 500 && attempt < _retryDelays.length) {
        _logger.debug('Retrying request', context: {'attempt': attempt + 1});
        await Future<void>.delayed(_retryDelays[attempt]);
        attempt++;
        continue;
      }

      throw ApiEnvelope.failureFrom(status, body);
    }
  }
}

/// Rejects a response whose body is not the CTMS envelope.
///
/// A proxy or captive portal returning HTML would otherwise surface as a
/// confusing parse error deep inside a feature.
class _EnvelopeInterceptor extends Interceptor {
  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    final data = response.data;

    if (data is Map<String, dynamic> && data.containsKey('success')) {
      handler.next(response);
      return;
    }

    handler.reject(
      DioException(
        requestOptions: response.requestOptions,
        message: 'Unexpected response shape',
      ),
    );
  }
}
