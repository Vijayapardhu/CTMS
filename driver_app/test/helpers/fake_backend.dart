import 'dart:convert';

import 'package:dio/dio.dart';

/// A scripted server.
///
/// Installed as Dio's [HttpClientAdapter], so requests go through the whole
/// real client — interceptors, envelope decoding, failure mapping — and stop
/// only at the socket. A mocked `ApiClient` would prove the tests agree with
/// themselves; this proves the client agrees with the backend's response shape.
class FakeBackend implements HttpClientAdapter {
  final List<_Route> _routes = [];

  /// Every request that reached the adapter, in order.
  final List<RecordedRequest> requests = [];

  /// Queues a response for the next request to [path].
  ///
  /// Queued per path, so a test can script "401 then 200" for the same URL and
  /// prove the replay actually happened.
  void on(
    String path, {
    required int status,
    required Map<String, dynamic> body,
    Duration delay = Duration.zero,
  }) {
    _routes.add(_Route(path, status, body, delay));
  }

  /// Fails the next request to [path] at the transport layer.
  void offline(String path) => _routes.add(_Route(path, null, null, Duration.zero));

  /// Discards every queued response.
  ///
  /// [on] appends, so a test that wants to *replace* a response the harness
  /// already scripted — the `/auth/me` a signed-in fixture queues — has to
  /// empty the queue first, or its own response sits behind one it never
  /// wanted. With nothing queued, every request fails at the transport layer,
  /// which is also how a test says "the server is unreachable".
  void clearScripts() => _routes.clear();

  int callsTo(String path) =>
      requests.where((r) => r.path.endsWith(path)).length;

  /// Every JSON body sent to [path], oldest first.
  List<Map<String, dynamic>> bodiesFor(String path) => requests
      .where((r) => r.path.endsWith(path) && r.body is Map<String, dynamic>)
      .map((r) => r.body! as Map<String, dynamic>)
      .toList(growable: false);

  /// The most recent JSON body sent to [path].
  Map<String, dynamic>? bodyFor(String path) => bodiesFor(path).lastOrNull;

  String? bearerFor(String path) => requests
      .lastWhere((r) => r.path.endsWith(path),
          orElse: () => throw StateError('No request to $path'))
      .bearer;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(RecordedRequest(
      path: options.path,
      method: options.method,
      bearer: (options.headers['Authorization'] as String?)
          ?.replaceFirst('Bearer ', ''),
      body: options.data,
      query: options.queryParameters.map((k, v) => MapEntry(k, '$v')),
    ));

    final index = _routes.indexWhere((r) => options.path.endsWith(r.path));

    if (index < 0) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'FakeBackend has no scripted response for ${options.path}',
      );
    }

    final route = _routes.removeAt(index);
    if (route.delay > Duration.zero) {
      await Future<void>.delayed(route.delay);
    }

    if (route.status == null) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'offline',
      );
    }

    return ResponseBody.fromString(
      _encode(route.body!),
      route.status!,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  String _encode(Map<String, dynamic> body) => const JsonEncoder().convert(body);

  @override
  void close({bool force = false}) {}
}

class RecordedRequest {
  const RecordedRequest({
    required this.path,
    required this.method,
    this.bearer,
    this.body,
    this.query = const {},
  });

  final String path;
  final String method;
  final String? bearer;
  final Object? body;

  /// Stringified, because a test asserting `date=2026-08-09` should not have to
  /// care whether the client sent a String or a DateTime.
  final Map<String, String> query;
}

class _Route {
  const _Route(this.path, this.status, this.body, this.delay);

  final String path;
  final int? status;
  final Map<String, dynamic>? body;
  final Duration delay;
}
