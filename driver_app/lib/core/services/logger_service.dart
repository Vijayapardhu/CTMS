import 'package:logger/logger.dart';

/// Structured logging with redaction.
///
/// Nothing that could identify a driver, a student, or a position ever reaches
/// a log sink. Coordinates are the easiest thing in this app to leak into
/// crash reports, so [redact] strips them before anything is emitted.
abstract interface class LoggerService {
  void debug(String message, {Map<String, Object?>? context});
  void info(String message, {Map<String, Object?>? context});
  void warn(String message, {Map<String, Object?>? context});
  void error(String message, {Object? error, StackTrace? stackTrace});
}

class ConsoleLoggerService implements LoggerService {
  ConsoleLoggerService({required bool verbose})
      : _logger = Logger(
          filter: verbose ? DevelopmentFilter() : ProductionFilter(),
          printer: PrettyPrinter(methodCount: 0, errorMethodCount: 6),
        );

  final Logger _logger;

  /// Keys whose values are never logged.
  static const _redacted = {
    'password', 'token', 'access_token', 'refresh_token', 'authorization',
    'latitude', 'longitude', 'email', 'phone_number', 'student_id',
  };

  static Map<String, Object?> redact(Map<String, Object?> context) {
    return context.map(
      (key, value) => MapEntry(
        key,
        _redacted.contains(key.toLowerCase()) ? '<redacted>' : value,
      ),
    );
  }

  String _format(String message, Map<String, Object?>? context) {
    if (context == null || context.isEmpty) return message;
    return '$message ${redact(context)}';
  }

  @override
  void debug(String message, {Map<String, Object?>? context}) =>
      _logger.d(_format(message, context));

  @override
  void info(String message, {Map<String, Object?>? context}) =>
      _logger.i(_format(message, context));

  @override
  void warn(String message, {Map<String, Object?>? context}) =>
      _logger.w(_format(message, context));

  @override
  void error(String message, {Object? error, StackTrace? stackTrace}) =>
      _logger.e(message, error: error, stackTrace: stackTrace);
}
