import 'package:dio/dio.dart';

import '../services/logger_service.dart';
import 'api_envelope.dart';
import 'api_failure.dart';

/// The HTTP boundary.
///
/// Two responsibilities and no more: attach the right headers, and turn a
/// response into either a payload or an [ApiFailure]. Business rules live in
/// the backend; retry policy lives here; everything else lives above.
///
/// Slice 0 wires the client and its interceptors. Nothing calls it yet — the
/// token supplier returns null until Slice 1 provides a session.
class ApiClient {
  ApiClient({
    required String baseUrl,
    required LoggerService logger,
    required Future<String?> Function() tokenSupplier,
    Dio? dio,
  })  : _logger = logger,
        _dio = dio ?? Dio() {
    _dio.options = BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 20),
      headers: {'Accept': 'application/json'},
      // Never throw on status: the envelope interceptor decides what is a
      // failure, so that a 409 arrives as a typed refusal rather than a
      // DioException the UI has to unpick.
      validateStatus: (_) => true,
    );

    _dio.interceptors.addAll([
      _AuthInterceptor(tokenSupplier),
      _EnvelopeInterceptor(),
      _RetryInterceptor(logger),
    ]);
  }

  final Dio _dio;
  final LoggerService _logger;

  /// Exposed so a later slice can register the refresh interceptor without
  /// reconstructing the client.
  Dio get dio => _dio;

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    return _send(() => _dio.get<dynamic>(path, queryParameters: query));
  }

  Future<Map<String, dynamic>> post(
    String path, {
    Object? body,
  }) async {
    return _send(() => _dio.post<dynamic>(path, data: body));
  }

  Future<Map<String, dynamic>> put(String path, {Object? body}) async {
    return _send(() => _dio.put<dynamic>(path, data: body));
  }

  Future<Map<String, dynamic>> patch(String path, {Object? body}) async {
    return _send(() => _dio.patch<dynamic>(path, data: body));
  }

  Future<Map<String, dynamic>> delete(String path) async {
    return _send(() => _dio.delete<dynamic>(path));
  }

  Future<Map<String, dynamic>> _send(
    Future<Response<dynamic>> Function() request,
  ) async {
    try {
      final response = await request();
      final body = response.data is Map<String, dynamic>
          ? response.data as Map<String, dynamic>
          : <String, dynamic>{};

      if (response.statusCode != null &&
          response.statusCode! >= 200 &&
          response.statusCode! < 300) {
        return body;
      }

      throw ApiEnvelope.failureFrom(response.statusCode ?? 0, body);
    } on DioException catch (e) {
      _logger.warn('Request failed', context: {'path': e.requestOptions.path});
      throw const NetworkFailure();
    }
  }
}

/// Attaches the bearer token.
///
/// A [QueuedInterceptor], not a plain one: when Slice 1 adds refresh, a burst
/// of parallel 401s must produce a single refresh. Five racing refreshes
/// against a rotating token invalidate each other and sign the driver out
/// mid-trip.
class _AuthInterceptor extends QueuedInterceptor {
  _AuthInterceptor(this._tokenSupplier);

  final Future<String?> Function() _tokenSupplier;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final token = await _tokenSupplier();

    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }

    handler.next(options);
  }
}

/// Rejects a response whose body is not the CTMS envelope.
///
/// A proxy or captive portal returning HTML would otherwise surface as a
/// confusing parse error deep in a feature.
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

/// Retries transient failures only.
///
/// 5xx and connection errors. **Never a 4xx**: a 409 is a considered refusal,
/// and retrying it is how a bus gets double-boarded.
class _RetryInterceptor extends Interceptor {
  _RetryInterceptor(this._logger);

  final LoggerService _logger;

  static const _maxAttempts = 2;
  static const _delays = [Duration(seconds: 1), Duration(seconds: 3)];

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final attempt = (err.requestOptions.extra['retry_attempt'] as int?) ?? 0;
    final status = err.response?.statusCode;
    final isTransient = status == null || status >= 500;

    if (!isTransient || attempt >= _maxAttempts) {
      handler.next(err);
      return;
    }

    await Future<void>.delayed(_delays[attempt]);

    _logger.debug('Retrying request', context: {
      'path': err.requestOptions.path,
      'attempt': attempt + 1,
    });

    err.requestOptions.extra['retry_attempt'] = attempt + 1;

    try {
      final response = await Dio(
        BaseOptions(
          baseUrl: err.requestOptions.baseUrl,
          validateStatus: (_) => true,
        ),
      ).fetch<dynamic>(err.requestOptions);

      handler.resolve(response);
    } on DioException catch (e) {
      handler.next(e);
    }
  }
}
