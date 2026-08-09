import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';

part 'sync_database.g.dart';

/// One driver action waiting to reach the server.
///
/// A real table rather than a serialised blob because the queue is read far
/// more often than it is written and always in order: a JSON list in
/// preferences would be rewritten whole on every GPS fix, and a ninety-minute
/// tunnel is several hundred of them.
///
/// [idempotencyKey] is written once, at insert, and never touched again. That
/// column is the whole offline contract: a key regenerated on retry turns one
/// boarding into five, and the server has no way to tell.
@DataClassName('QueuedActionRow')
class QueuedActions extends Table {
  TextColumn get id => text()();

  /// Which operation this is. Matched against the handlers the engine knows.
  TextColumn get kind => text()();

  /// The request body, as JSON.
  TextColumn get payload => text()();

  TextColumn get idempotencyKey => text()();

  /// FIFO within a trip. Assigned at enqueue from a per-trip counter, so an
  /// arrival recorded after a boarding can never replay before it.
  IntColumn get sequence => integer()();

  DateTimeColumn get createdAt => dateTime()();

  TextColumn get tripId => text().nullable()();

  IntColumn get attempts => integer().withDefault(const Constant(0))();

  /// The server's own message when this was permanently refused. Kept so the
  /// queue can show what failed and why, in the words the driver will be given
  /// on the phone if they ring the office.
  TextColumn get lastFailure => text().nullable()();

  /// Carries more than one call — an incident and its photograph — which
  /// cannot be split because the report cannot cite an evidence id that does
  /// not exist yet.
  BoolColumn get isCompound => boolean().withDefault(const Constant(false))();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

@DriftDatabase(tables: [QueuedActions])
class SyncDatabase extends _$SyncDatabase {
  SyncDatabase() : super(driftDatabase(name: 'ctms_sync'));

  /// In-memory, for tests. Same schema, same queries, no file.
  SyncDatabase.forTesting(super.executor);

  @override
  int get schemaVersion => 1;
}
