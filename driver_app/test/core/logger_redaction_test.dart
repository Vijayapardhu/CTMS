import 'package:ctms_driver/core/services/logger_service.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('log redaction', () {
    test('strips credentials', () {
      final redacted = ConsoleLoggerService.redact({
        'password': 'hunter2',
        'access_token': 'eyJhbGciOi',
        'refresh_token': 'rt_abc',
      });

      expect(redacted.values, everyElement('<redacted>'));
    });

    test('strips coordinates', () {
      final redacted = ConsoleLoggerService.redact({
        'latitude': 17.3850,
        'longitude': 78.4867,
      });

      expect(
        redacted,
        {'latitude': '<redacted>', 'longitude': '<redacted>'},
        reason: "a driver's route is their movements, and a log sink is not a "
            'place to keep them',
      );
    });

    test('strips personal identifiers', () {
      final redacted = ConsoleLoggerService.redact({
        'email': 'driver@example.com',
        'phone_number': '+919999999999',
        'student_id': 'CS21B1001',
      });

      expect(redacted.values, everyElement('<redacted>'));
    });

    test('is case-insensitive about the key', () {
      final redacted = ConsoleLoggerService.redact({'Password': 'hunter2'});

      expect(redacted['Password'], '<redacted>');
    });

    test('leaves operational context alone', () {
      final redacted = ConsoleLoggerService.redact({
        'trip_id': 'a1b2',
        'status': 'RUNNING',
        'attempt': 2,
      });

      expect(redacted, {'trip_id': 'a1b2', 'status': 'RUNNING', 'attempt': 2});
    });

    test('an empty context stays empty', () {
      expect(ConsoleLoggerService.redact({}), isEmpty);
    });
  });
}
