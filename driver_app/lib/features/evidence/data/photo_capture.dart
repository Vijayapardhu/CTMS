import 'dart:math';

import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:image_picker/image_picker.dart';

import '../domain/evidence.dart';

/// Takes a photograph and makes it small enough to send.
///
/// An interface because the camera is a platform channel: a test can prove the
/// upload rules without a device, and the compression numbers stay in one place
/// rather than spread across call sites.
abstract interface class PhotoCapture {
  /// Opens the camera. Returns null if the driver backed out.
  Future<CapturedPhoto?> take();
}

class DevicePhotoCapture implements PhotoCapture {
  DevicePhotoCapture({ImagePicker? picker, Random? random})
      : _picker = picker ?? ImagePicker(),
        _random = random ?? Random.secure();

  final ImagePicker _picker;
  final Random _random;

  /// ≤ 1920px on the long edge at JPEG q80.
  ///
  /// A 12MP original is around 6MB, which is minutes over a rural connection
  /// and often over the server's ceiling outright. At these settings a brake
  /// line is still legible to a workshop and the file is a few hundred KB.
  static const _maxEdge = 1920;
  static const _quality = 80;

  @override
  Future<CapturedPhoto?> take() async {
    final shot = await _picker.pickImage(
      source: ImageSource.camera,
      // The picker resizes before the bytes ever reach Dart, which keeps a
      // 12MP frame out of memory on a low-end handset.
      maxWidth: _maxEdge.toDouble(),
      maxHeight: _maxEdge.toDouble(),
      imageQuality: _quality,
    );

    if (shot == null) return null;

    final original = await shot.readAsBytes();

    // Second pass to normalise: the picker honours quality inconsistently
    // across OEM camera apps, and HEIC from an iPhone-style pipeline has to
    // become something the server's accepted list contains.
    final compressed = await FlutterImageCompress.compressWithList(
      original,
      minWidth: _maxEdge,
      minHeight: _maxEdge,
      quality: _quality,
      format: CompressFormat.jpeg,
    );

    return CapturedPhoto(
      bytes: compressed,
      mimeType: 'image/jpeg',
      fileName: _name(),
    );
  }

  /// Generated, never the camera's. A sequential or timestamped name is one
  /// somebody else can guess.
  String _name() {
    final suffix = List.generate(
      16,
      (_) => _random.nextInt(16).toRadixString(16),
    ).join();

    return 'evidence-$suffix.jpg';
  }
}
