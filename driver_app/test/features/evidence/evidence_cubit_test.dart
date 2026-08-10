import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/services/permission_service.dart';
import 'package:ctms_driver/features/evidence/data/evidence_api.dart';
import 'package:ctms_driver/features/evidence/domain/evidence.dart';
import 'package:ctms_driver/features/evidence/domain/evidence_state.dart';
import 'package:ctms_driver/features/evidence/presentation/bloc/evidence_cubit.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/evidence_fixtures.dart';
import '../../helpers/inspection_fixtures.dart';
import '../../helpers/fake_backend.dart';
import '../../helpers/test_doubles.dart';

class _Reach implements ConnectivityService {
  @override
  Reachability current = Reachability.online;

  @override
  Stream<Reachability> get changes => const Stream.empty();

  @override
  void recordFailure() {}

  @override
  void recordSuccess() {}
}

void main() {
  late FakeBackend backend;
  late FakeCamera camera;
  late FakePermissions permissions;
  late _Reach reach;

  EvidenceCubit build() {
    final client = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      retryDelays: const [],
    )..dio.httpClientAdapter = backend;

    return EvidenceCubit(
      api: EvidenceApi(client),
      capture: camera,
      permissions: permissions,
      connectivity: reach,
      category: EvidenceCategory.inspectionPhoto,
    );
  }

  setUp(() {
    backend = FakeBackend();
    camera = FakeCamera();
    permissions = FakePermissions();
    reach = _Reach();
  });

  group('permission', () {
    test('asks for the camera, then opens it', () async {
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();

      expect(cubit.state, isA<EvidencePreviewing>());
      expect(camera.takes, 1);
    });

    test('a denial blocks rather than silently doing nothing', () async {
      permissions.answer = PermissionStatus.denied;
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();

      expect(cubit.state, isA<EvidenceBlocked>());
      expect((cubit.state as EvidenceBlocked).reason, CameraBlock.denied);
      expect(
        (cubit.state as EvidenceBlocked).settingsCanFix,
        isFalse,
        reason: 'a refusal this time can simply be asked again',
      );
      expect(camera.takes, 0);
    });

    test('a permanent denial says so, because only Settings can undo it',
        () async {
      permissions.answer = PermissionStatus.permanentlyDenied;
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();

      expect(
        (cubit.state as EvidenceBlocked).reason,
        CameraBlock.permanentlyDenied,
      );
      expect((cubit.state as EvidenceBlocked).settingsCanFix, isTrue);
    });

    test('a handset that forbids the camera is not a driver who refused it',
        () async {
      permissions.answer = PermissionStatus.restricted;
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();

      expect(
        (cubit.state as EvidenceBlocked).reason,
        CameraBlock.unavailable,
      );
      expect(
        (cubit.state as EvidenceBlocked).settingsCanFix,
        isFalse,
        reason: 'sending a driver to Settings for something Settings cannot '
            'change is an errand, not help',
      );
    });

    test('opening settings is offered, not faked', () async {
      permissions.answer = PermissionStatus.permanentlyDenied;
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.openSettings();

      expect(permissions.settingsOpened, 1);
    });
  });

  group('capture', () {
    test('backing out of the camera leaves nothing attached', () async {
      camera.cancelled = true;
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();

      expect(cubit.state, isA<EvidenceIdle>());
    });

    test('nothing is uploaded on capture — only on confirm', () async {
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();

      expect(
        backend.callsTo('/evidence'),
        0,
        reason: 'an upload never cited is swept after 48 hours; uploading '
            'every discarded frame spends a driver\'s data on nothing',
      );
    });

    test('retake replaces the photograph', () async {
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.retake();

      expect(camera.takes, 2);
      expect(cubit.state, isA<EvidencePreviewing>());
    });
  });

  group('upload', () {
    test('confirming sends the bytes and the category', () async {
      backend
        ..on('/evidence/categories', status: 200, body: categoriesResponse())
        ..on('/evidence', status: 201, body: uploadResponse());

      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();

      expect(cubit.state, isA<EvidenceUploaded>());
      expect(cubit.state.evidenceId, 'evidence-1');

      final sent = backend.requests.last;
      expect(sent.method, 'POST');
      expect(sent.path, contains('/evidence'));
    });

    test('the category comes from the caller, never a picker', () async {
      backend
        ..on('/evidence/categories', status: 200, body: categoriesResponse())
        ..on('/evidence', status: 201, body: uploadResponse());

      final client = ApiClient(
        baseUrl: 'http://localhost/api/v1',
        logger: SilentLogger(),
        retryDelays: const [],
      )..dio.httpClientAdapter = backend;

      final cubit = EvidenceCubit(
        api: EvidenceApi(client),
        capture: camera,
        permissions: permissions,
        connectivity: reach,
        category: EvidenceCategory.incidentPhoto,
      );
      addTearDown(cubit.close);

      expect(cubit.category.wire, 'INCIDENT_PHOTO');
    });

    test('a refusal shows the server wording and offers a retake', () async {
      backend
        ..on('/evidence/categories', status: 200, body: categoriesResponse())
        ..on('/evidence',
            status: 409,
            body: errorEnvelope('Photographs only (JPEG, PNG, HEIC, WebP).'));

      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();

      expect(cubit.state, isA<EvidenceRejected>());
      expect(
        (cubit.state as EvidenceRejected).reason.message,
        'Photographs only (JPEG, PNG, HEIC, WebP).',
        reason: 'never paraphrase a 409',
      );
    });

    test('a refused upload is not retried behind the driver', () async {
      backend
        ..on('/evidence/categories', status: 200, body: categoriesResponse())
        ..on('/evidence', status: 409, body: errorEnvelope('No.'));

      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();

      expect(backend.callsTo('/evidence'), 1);
    });

    test('a file over the server ceiling is refused before it is sent',
        () async {
      backend.on('/evidence/categories',
          status: 200, body: categoriesResponse(maxBytes: 1024));

      camera.photo = CapturedPhoto(
        bytes: List<int>.filled(4096, 1),
        mimeType: 'image/jpeg',
        fileName: 'big.jpg',
      );

      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();

      expect(cubit.state, isA<EvidenceRejected>());
      expect(
        (cubit.state as EvidenceRejected).reason.message,
        contains('Maximum'),
      );
      expect(
        backend.callsTo('/evidence'),
        0,
        reason: 'the ceiling is the server\'s own, read from its categories; '
            'spending a rural upload to be told is a waste',
      );
    });

    test('the ceiling comes from the server, not from a constant', () async {
      backend.on('/evidence/categories',
          status: 200, body: categoriesResponse(maxBytes: 16 * 1024 * 1024));
      backend.on('/evidence', status: 201, body: uploadResponse());

      // 10 MB — over the documented 8 MB default, under this server's ceiling.
      camera.photo = CapturedPhoto(
        bytes: List<int>.filled(10 * 1024 * 1024, 1),
        mimeType: 'image/jpeg',
        fileName: 'big.jpg',
      );

      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();

      expect(
        cubit.state,
        isA<EvidenceUploaded>(),
        reason: 'a client that hard-codes 8 MB rejects a photograph the '
            'backend would have taken',
      );
    });

    test('unreadable limits fall back to the documented default', () async {
      backend
        ..on('/evidence/categories', status: 500, body: errorEnvelope('Down.'))
        ..on('/evidence', status: 201, body: uploadResponse());

      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();

      expect(
        cubit.state,
        isA<EvidenceUploaded>(),
        reason: 'reference data failing must not stop a driver evidencing a '
            'brake failure',
      );
    });
  });

  group('offline', () {
    test('the photograph is held and the id is not pretended', () async {
      reach.current = Reachability.offline;
      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();

      expect(cubit.state, isA<EvidenceQueued>());
      expect(
        cubit.state.evidenceId,
        isNull,
        reason: 'the bytes exist; the id does not, and anything citing it '
            'cannot be submitted',
      );
      expect(backend.callsTo('/evidence'), 0);
    });

    test('a network failure mid-upload queues rather than losing the photo',
        () async {
      backend
        ..on('/evidence/categories', status: 200, body: categoriesResponse())
        ..offline('/evidence');

      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();

      expect(cubit.state, isA<EvidenceQueued>());
      expect(cubit.state.photo, isNotNull);
    });
  });

  group('withdrawing', () {
    test('clearing drops the id as well as the photograph', () async {
      backend
        ..on('/evidence/categories', status: 200, body: categoriesResponse())
        ..on('/evidence', status: 201, body: uploadResponse());

      final cubit = build();
      addTearDown(cubit.close);

      await cubit.capture();
      await cubit.confirm();
      expect(cubit.state.evidenceId, isNotNull);

      cubit.clear();

      expect(cubit.state, isA<EvidenceIdle>());
      expect(
        cubit.state.evidenceId,
        isNull,
        reason: 're-citing an attached id is a 409; the app makes re-use '
            'impossible by letting go of it',
      );
    });
  });
}
