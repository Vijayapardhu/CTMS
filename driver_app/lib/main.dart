import 'app/bootstrap.dart';

/// Entry point.
///
/// Deliberately empty of logic. Everything that could fail lives in
/// [bootstrap], inside the guarded zone — a `main` that does work is a `main`
/// whose failures nothing catches.
void main() => bootstrap();
