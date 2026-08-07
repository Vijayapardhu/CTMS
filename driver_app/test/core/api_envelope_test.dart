import 'package:ctms_driver/core/api/api_envelope.dart';
import 'package:ctms_driver/core/api/api_failure.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('ApiEnvelope.failureFrom', () {
    /// The shape `App\Support\ApiError::response()` actually returns.
    Map<String, dynamic> body(String message, {Map<String, dynamic>? errors}) => {
          'success': false,
          'message': message,
          'data': null,
          'errors': errors,
        };

    test('401 is an authentication failure', () {
      expect(
        ApiEnvelope.failureFrom(401, body('Unauthenticated')),
        isA<AuthFailure>(),
      );
    });

    test('403 is distinct from 401', () {
      expect(
        ApiEnvelope.failureFrom(403, body('Forbidden')),
        isA<ForbiddenFailure>(),
        reason: 'signing the driver out on a 403 would log them out for '
            'tapping something they were never allowed to tap',
      );
    });

    test('404 is a not-found failure', () {
      expect(
        ApiEnvelope.failureFrom(404, body('No such trip')),
        isA<NotFoundFailure>(),
      );
    });

    test('409 carries the refusal context so the UI can explain it', () {
      final failure = ApiEnvelope.failureFrom(
        409,
        body('Trip cannot start', errors: {
          'reasons': ['Inspection not submitted', 'Bus is in maintenance'],
        }),
      );

      expect(failure, isA<ConflictFailure>());
      expect(
        (failure as ConflictFailure).reasons,
        ['Inspection not submitted', 'Bus is in maintenance'],
      );
    });

    test('a 409 with no reasons still produces a usable failure', () {
      final failure = ApiEnvelope.failureFrom(409, body('Already started'));

      expect(failure, isA<ConflictFailure>());
      expect((failure as ConflictFailure).reasons, isEmpty);
      expect(failure.message, 'Already started');
    });

    test('422 exposes the field errors', () {
      final failure = ApiEnvelope.failureFrom(
        422,
        body('The given data was invalid.', errors: {
          'odometer_reading': ['The odometer reading must be a number.'],
        }),
      );

      expect(failure, isA<ValidationFailure>());
      expect(
        (failure as ValidationFailure).firstFor('odometer_reading'),
        'The odometer reading must be a number.',
      );
    });

    test('a 422 whose errors are scalars is still readable', () {
      final failure = ApiEnvelope.failureFrom(
        422,
        body('Invalid', errors: {'odometer_reading': 'Not a number.'}),
      ) as ValidationFailure;

      expect(failure.firstFor('odometer_reading'), 'Not a number.');
    });

    test('429 is a rate-limit failure', () {
      expect(
        ApiEnvelope.failureFrom(429, body('Too many attempts')),
        isA<RateLimitFailure>(),
      );
    });

    test('500 is a server failure', () {
      expect(
        ApiEnvelope.failureFrom(500, body('Server error')),
        isA<ServerFailure>(),
      );
    });

    test('an unmapped status does not silently become a success', () {
      final failure = ApiEnvelope.failureFrom(418, body('Teapot'));

      expect(failure, isA<ApiFailure>());
    });

    test('the message shown to the driver comes from the envelope', () {
      final failure = ApiEnvelope.failureFrom(404, body('That trip has ended'));

      expect(failure.message, 'That trip has ended');
    });
  });
}
