// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'sync_database.dart';

// ignore_for_file: type=lint
class $QueuedActionsTable extends QueuedActions
    with TableInfo<$QueuedActionsTable, QueuedActionRow> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $QueuedActionsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
      'id', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _kindMeta = const VerificationMeta('kind');
  @override
  late final GeneratedColumn<String> kind = GeneratedColumn<String>(
      'kind', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _payloadMeta =
      const VerificationMeta('payload');
  @override
  late final GeneratedColumn<String> payload = GeneratedColumn<String>(
      'payload', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _idempotencyKeyMeta =
      const VerificationMeta('idempotencyKey');
  @override
  late final GeneratedColumn<String> idempotencyKey = GeneratedColumn<String>(
      'idempotency_key', aliasedName, false,
      type: DriftSqlType.string, requiredDuringInsert: true);
  static const VerificationMeta _sequenceMeta =
      const VerificationMeta('sequence');
  @override
  late final GeneratedColumn<int> sequence = GeneratedColumn<int>(
      'sequence', aliasedName, false,
      type: DriftSqlType.int, requiredDuringInsert: true);
  static const VerificationMeta _createdAtMeta =
      const VerificationMeta('createdAt');
  @override
  late final GeneratedColumn<DateTime> createdAt = GeneratedColumn<DateTime>(
      'created_at', aliasedName, false,
      type: DriftSqlType.dateTime, requiredDuringInsert: true);
  static const VerificationMeta _tripIdMeta = const VerificationMeta('tripId');
  @override
  late final GeneratedColumn<String> tripId = GeneratedColumn<String>(
      'trip_id', aliasedName, true,
      type: DriftSqlType.string, requiredDuringInsert: false);
  static const VerificationMeta _attemptsMeta =
      const VerificationMeta('attempts');
  @override
  late final GeneratedColumn<int> attempts = GeneratedColumn<int>(
      'attempts', aliasedName, false,
      type: DriftSqlType.int,
      requiredDuringInsert: false,
      defaultValue: const Constant(0));
  static const VerificationMeta _lastFailureMeta =
      const VerificationMeta('lastFailure');
  @override
  late final GeneratedColumn<String> lastFailure = GeneratedColumn<String>(
      'last_failure', aliasedName, true,
      type: DriftSqlType.string, requiredDuringInsert: false);
  static const VerificationMeta _isCompoundMeta =
      const VerificationMeta('isCompound');
  @override
  late final GeneratedColumn<bool> isCompound = GeneratedColumn<bool>(
      'is_compound', aliasedName, false,
      type: DriftSqlType.bool,
      requiredDuringInsert: false,
      defaultConstraints:
          GeneratedColumn.constraintIsAlways('CHECK ("is_compound" IN (0, 1))'),
      defaultValue: const Constant(false));
  @override
  List<GeneratedColumn> get $columns => [
        id,
        kind,
        payload,
        idempotencyKey,
        sequence,
        createdAt,
        tripId,
        attempts,
        lastFailure,
        isCompound
      ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'queued_actions';
  @override
  VerificationContext validateIntegrity(Insertable<QueuedActionRow> instance,
      {bool isInserting = false}) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('kind')) {
      context.handle(
          _kindMeta, kind.isAcceptableOrUnknown(data['kind']!, _kindMeta));
    } else if (isInserting) {
      context.missing(_kindMeta);
    }
    if (data.containsKey('payload')) {
      context.handle(_payloadMeta,
          payload.isAcceptableOrUnknown(data['payload']!, _payloadMeta));
    } else if (isInserting) {
      context.missing(_payloadMeta);
    }
    if (data.containsKey('idempotency_key')) {
      context.handle(
          _idempotencyKeyMeta,
          idempotencyKey.isAcceptableOrUnknown(
              data['idempotency_key']!, _idempotencyKeyMeta));
    } else if (isInserting) {
      context.missing(_idempotencyKeyMeta);
    }
    if (data.containsKey('sequence')) {
      context.handle(_sequenceMeta,
          sequence.isAcceptableOrUnknown(data['sequence']!, _sequenceMeta));
    } else if (isInserting) {
      context.missing(_sequenceMeta);
    }
    if (data.containsKey('created_at')) {
      context.handle(_createdAtMeta,
          createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta));
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    if (data.containsKey('trip_id')) {
      context.handle(_tripIdMeta,
          tripId.isAcceptableOrUnknown(data['trip_id']!, _tripIdMeta));
    }
    if (data.containsKey('attempts')) {
      context.handle(_attemptsMeta,
          attempts.isAcceptableOrUnknown(data['attempts']!, _attemptsMeta));
    }
    if (data.containsKey('last_failure')) {
      context.handle(
          _lastFailureMeta,
          lastFailure.isAcceptableOrUnknown(
              data['last_failure']!, _lastFailureMeta));
    }
    if (data.containsKey('is_compound')) {
      context.handle(
          _isCompoundMeta,
          isCompound.isAcceptableOrUnknown(
              data['is_compound']!, _isCompoundMeta));
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  QueuedActionRow map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return QueuedActionRow(
      id: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}id'])!,
      kind: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}kind'])!,
      payload: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}payload'])!,
      idempotencyKey: attachedDatabase.typeMapping.read(
          DriftSqlType.string, data['${effectivePrefix}idempotency_key'])!,
      sequence: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}sequence'])!,
      createdAt: attachedDatabase.typeMapping
          .read(DriftSqlType.dateTime, data['${effectivePrefix}created_at'])!,
      tripId: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}trip_id']),
      attempts: attachedDatabase.typeMapping
          .read(DriftSqlType.int, data['${effectivePrefix}attempts'])!,
      lastFailure: attachedDatabase.typeMapping
          .read(DriftSqlType.string, data['${effectivePrefix}last_failure']),
      isCompound: attachedDatabase.typeMapping
          .read(DriftSqlType.bool, data['${effectivePrefix}is_compound'])!,
    );
  }

  @override
  $QueuedActionsTable createAlias(String alias) {
    return $QueuedActionsTable(attachedDatabase, alias);
  }
}

class QueuedActionRow extends DataClass implements Insertable<QueuedActionRow> {
  final String id;

  /// Which operation this is. Matched against the handlers the engine knows.
  final String kind;

  /// The request body, as JSON.
  final String payload;
  final String idempotencyKey;

  /// FIFO within a trip. Assigned at enqueue from a per-trip counter, so an
  /// arrival recorded after a boarding can never replay before it.
  final int sequence;
  final DateTime createdAt;
  final String? tripId;
  final int attempts;

  /// The server's own message when this was permanently refused. Kept so the
  /// queue can show what failed and why, in the words the driver will be given
  /// on the phone if they ring the office.
  final String? lastFailure;

  /// Carries more than one call — an incident and its photograph — which
  /// cannot be split because the report cannot cite an evidence id that does
  /// not exist yet.
  final bool isCompound;
  const QueuedActionRow(
      {required this.id,
      required this.kind,
      required this.payload,
      required this.idempotencyKey,
      required this.sequence,
      required this.createdAt,
      this.tripId,
      required this.attempts,
      this.lastFailure,
      required this.isCompound});
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['kind'] = Variable<String>(kind);
    map['payload'] = Variable<String>(payload);
    map['idempotency_key'] = Variable<String>(idempotencyKey);
    map['sequence'] = Variable<int>(sequence);
    map['created_at'] = Variable<DateTime>(createdAt);
    if (!nullToAbsent || tripId != null) {
      map['trip_id'] = Variable<String>(tripId);
    }
    map['attempts'] = Variable<int>(attempts);
    if (!nullToAbsent || lastFailure != null) {
      map['last_failure'] = Variable<String>(lastFailure);
    }
    map['is_compound'] = Variable<bool>(isCompound);
    return map;
  }

  QueuedActionsCompanion toCompanion(bool nullToAbsent) {
    return QueuedActionsCompanion(
      id: Value(id),
      kind: Value(kind),
      payload: Value(payload),
      idempotencyKey: Value(idempotencyKey),
      sequence: Value(sequence),
      createdAt: Value(createdAt),
      tripId:
          tripId == null && nullToAbsent ? const Value.absent() : Value(tripId),
      attempts: Value(attempts),
      lastFailure: lastFailure == null && nullToAbsent
          ? const Value.absent()
          : Value(lastFailure),
      isCompound: Value(isCompound),
    );
  }

  factory QueuedActionRow.fromJson(Map<String, dynamic> json,
      {ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return QueuedActionRow(
      id: serializer.fromJson<String>(json['id']),
      kind: serializer.fromJson<String>(json['kind']),
      payload: serializer.fromJson<String>(json['payload']),
      idempotencyKey: serializer.fromJson<String>(json['idempotencyKey']),
      sequence: serializer.fromJson<int>(json['sequence']),
      createdAt: serializer.fromJson<DateTime>(json['createdAt']),
      tripId: serializer.fromJson<String?>(json['tripId']),
      attempts: serializer.fromJson<int>(json['attempts']),
      lastFailure: serializer.fromJson<String?>(json['lastFailure']),
      isCompound: serializer.fromJson<bool>(json['isCompound']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'kind': serializer.toJson<String>(kind),
      'payload': serializer.toJson<String>(payload),
      'idempotencyKey': serializer.toJson<String>(idempotencyKey),
      'sequence': serializer.toJson<int>(sequence),
      'createdAt': serializer.toJson<DateTime>(createdAt),
      'tripId': serializer.toJson<String?>(tripId),
      'attempts': serializer.toJson<int>(attempts),
      'lastFailure': serializer.toJson<String?>(lastFailure),
      'isCompound': serializer.toJson<bool>(isCompound),
    };
  }

  QueuedActionRow copyWith(
          {String? id,
          String? kind,
          String? payload,
          String? idempotencyKey,
          int? sequence,
          DateTime? createdAt,
          Value<String?> tripId = const Value.absent(),
          int? attempts,
          Value<String?> lastFailure = const Value.absent(),
          bool? isCompound}) =>
      QueuedActionRow(
        id: id ?? this.id,
        kind: kind ?? this.kind,
        payload: payload ?? this.payload,
        idempotencyKey: idempotencyKey ?? this.idempotencyKey,
        sequence: sequence ?? this.sequence,
        createdAt: createdAt ?? this.createdAt,
        tripId: tripId.present ? tripId.value : this.tripId,
        attempts: attempts ?? this.attempts,
        lastFailure: lastFailure.present ? lastFailure.value : this.lastFailure,
        isCompound: isCompound ?? this.isCompound,
      );
  QueuedActionRow copyWithCompanion(QueuedActionsCompanion data) {
    return QueuedActionRow(
      id: data.id.present ? data.id.value : this.id,
      kind: data.kind.present ? data.kind.value : this.kind,
      payload: data.payload.present ? data.payload.value : this.payload,
      idempotencyKey: data.idempotencyKey.present
          ? data.idempotencyKey.value
          : this.idempotencyKey,
      sequence: data.sequence.present ? data.sequence.value : this.sequence,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
      tripId: data.tripId.present ? data.tripId.value : this.tripId,
      attempts: data.attempts.present ? data.attempts.value : this.attempts,
      lastFailure:
          data.lastFailure.present ? data.lastFailure.value : this.lastFailure,
      isCompound:
          data.isCompound.present ? data.isCompound.value : this.isCompound,
    );
  }

  @override
  String toString() {
    return (StringBuffer('QueuedActionRow(')
          ..write('id: $id, ')
          ..write('kind: $kind, ')
          ..write('payload: $payload, ')
          ..write('idempotencyKey: $idempotencyKey, ')
          ..write('sequence: $sequence, ')
          ..write('createdAt: $createdAt, ')
          ..write('tripId: $tripId, ')
          ..write('attempts: $attempts, ')
          ..write('lastFailure: $lastFailure, ')
          ..write('isCompound: $isCompound')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(id, kind, payload, idempotencyKey, sequence,
      createdAt, tripId, attempts, lastFailure, isCompound);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is QueuedActionRow &&
          other.id == this.id &&
          other.kind == this.kind &&
          other.payload == this.payload &&
          other.idempotencyKey == this.idempotencyKey &&
          other.sequence == this.sequence &&
          other.createdAt == this.createdAt &&
          other.tripId == this.tripId &&
          other.attempts == this.attempts &&
          other.lastFailure == this.lastFailure &&
          other.isCompound == this.isCompound);
}

class QueuedActionsCompanion extends UpdateCompanion<QueuedActionRow> {
  final Value<String> id;
  final Value<String> kind;
  final Value<String> payload;
  final Value<String> idempotencyKey;
  final Value<int> sequence;
  final Value<DateTime> createdAt;
  final Value<String?> tripId;
  final Value<int> attempts;
  final Value<String?> lastFailure;
  final Value<bool> isCompound;
  final Value<int> rowid;
  const QueuedActionsCompanion({
    this.id = const Value.absent(),
    this.kind = const Value.absent(),
    this.payload = const Value.absent(),
    this.idempotencyKey = const Value.absent(),
    this.sequence = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.tripId = const Value.absent(),
    this.attempts = const Value.absent(),
    this.lastFailure = const Value.absent(),
    this.isCompound = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  QueuedActionsCompanion.insert({
    required String id,
    required String kind,
    required String payload,
    required String idempotencyKey,
    required int sequence,
    required DateTime createdAt,
    this.tripId = const Value.absent(),
    this.attempts = const Value.absent(),
    this.lastFailure = const Value.absent(),
    this.isCompound = const Value.absent(),
    this.rowid = const Value.absent(),
  })  : id = Value(id),
        kind = Value(kind),
        payload = Value(payload),
        idempotencyKey = Value(idempotencyKey),
        sequence = Value(sequence),
        createdAt = Value(createdAt);
  static Insertable<QueuedActionRow> custom({
    Expression<String>? id,
    Expression<String>? kind,
    Expression<String>? payload,
    Expression<String>? idempotencyKey,
    Expression<int>? sequence,
    Expression<DateTime>? createdAt,
    Expression<String>? tripId,
    Expression<int>? attempts,
    Expression<String>? lastFailure,
    Expression<bool>? isCompound,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (kind != null) 'kind': kind,
      if (payload != null) 'payload': payload,
      if (idempotencyKey != null) 'idempotency_key': idempotencyKey,
      if (sequence != null) 'sequence': sequence,
      if (createdAt != null) 'created_at': createdAt,
      if (tripId != null) 'trip_id': tripId,
      if (attempts != null) 'attempts': attempts,
      if (lastFailure != null) 'last_failure': lastFailure,
      if (isCompound != null) 'is_compound': isCompound,
      if (rowid != null) 'rowid': rowid,
    });
  }

  QueuedActionsCompanion copyWith(
      {Value<String>? id,
      Value<String>? kind,
      Value<String>? payload,
      Value<String>? idempotencyKey,
      Value<int>? sequence,
      Value<DateTime>? createdAt,
      Value<String?>? tripId,
      Value<int>? attempts,
      Value<String?>? lastFailure,
      Value<bool>? isCompound,
      Value<int>? rowid}) {
    return QueuedActionsCompanion(
      id: id ?? this.id,
      kind: kind ?? this.kind,
      payload: payload ?? this.payload,
      idempotencyKey: idempotencyKey ?? this.idempotencyKey,
      sequence: sequence ?? this.sequence,
      createdAt: createdAt ?? this.createdAt,
      tripId: tripId ?? this.tripId,
      attempts: attempts ?? this.attempts,
      lastFailure: lastFailure ?? this.lastFailure,
      isCompound: isCompound ?? this.isCompound,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (kind.present) {
      map['kind'] = Variable<String>(kind.value);
    }
    if (payload.present) {
      map['payload'] = Variable<String>(payload.value);
    }
    if (idempotencyKey.present) {
      map['idempotency_key'] = Variable<String>(idempotencyKey.value);
    }
    if (sequence.present) {
      map['sequence'] = Variable<int>(sequence.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<DateTime>(createdAt.value);
    }
    if (tripId.present) {
      map['trip_id'] = Variable<String>(tripId.value);
    }
    if (attempts.present) {
      map['attempts'] = Variable<int>(attempts.value);
    }
    if (lastFailure.present) {
      map['last_failure'] = Variable<String>(lastFailure.value);
    }
    if (isCompound.present) {
      map['is_compound'] = Variable<bool>(isCompound.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('QueuedActionsCompanion(')
          ..write('id: $id, ')
          ..write('kind: $kind, ')
          ..write('payload: $payload, ')
          ..write('idempotencyKey: $idempotencyKey, ')
          ..write('sequence: $sequence, ')
          ..write('createdAt: $createdAt, ')
          ..write('tripId: $tripId, ')
          ..write('attempts: $attempts, ')
          ..write('lastFailure: $lastFailure, ')
          ..write('isCompound: $isCompound, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

abstract class _$SyncDatabase extends GeneratedDatabase {
  _$SyncDatabase(QueryExecutor e) : super(e);
  $SyncDatabaseManager get managers => $SyncDatabaseManager(this);
  late final $QueuedActionsTable queuedActions = $QueuedActionsTable(this);
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [queuedActions];
}

typedef $$QueuedActionsTableCreateCompanionBuilder = QueuedActionsCompanion
    Function({
  required String id,
  required String kind,
  required String payload,
  required String idempotencyKey,
  required int sequence,
  required DateTime createdAt,
  Value<String?> tripId,
  Value<int> attempts,
  Value<String?> lastFailure,
  Value<bool> isCompound,
  Value<int> rowid,
});
typedef $$QueuedActionsTableUpdateCompanionBuilder = QueuedActionsCompanion
    Function({
  Value<String> id,
  Value<String> kind,
  Value<String> payload,
  Value<String> idempotencyKey,
  Value<int> sequence,
  Value<DateTime> createdAt,
  Value<String?> tripId,
  Value<int> attempts,
  Value<String?> lastFailure,
  Value<bool> isCompound,
  Value<int> rowid,
});

class $$QueuedActionsTableFilterComposer
    extends FilterComposer<_$SyncDatabase, $QueuedActionsTable> {
  $$QueuedActionsTableFilterComposer(super.$state);
  ColumnFilters<String> get id => $state.composableBuilder(
      column: $state.table.id,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<String> get kind => $state.composableBuilder(
      column: $state.table.kind,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<String> get payload => $state.composableBuilder(
      column: $state.table.payload,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<String> get idempotencyKey => $state.composableBuilder(
      column: $state.table.idempotencyKey,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<int> get sequence => $state.composableBuilder(
      column: $state.table.sequence,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<DateTime> get createdAt => $state.composableBuilder(
      column: $state.table.createdAt,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<String> get tripId => $state.composableBuilder(
      column: $state.table.tripId,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<int> get attempts => $state.composableBuilder(
      column: $state.table.attempts,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<String> get lastFailure => $state.composableBuilder(
      column: $state.table.lastFailure,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));

  ColumnFilters<bool> get isCompound => $state.composableBuilder(
      column: $state.table.isCompound,
      builder: (column, joinBuilders) =>
          ColumnFilters(column, joinBuilders: joinBuilders));
}

class $$QueuedActionsTableOrderingComposer
    extends OrderingComposer<_$SyncDatabase, $QueuedActionsTable> {
  $$QueuedActionsTableOrderingComposer(super.$state);
  ColumnOrderings<String> get id => $state.composableBuilder(
      column: $state.table.id,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<String> get kind => $state.composableBuilder(
      column: $state.table.kind,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<String> get payload => $state.composableBuilder(
      column: $state.table.payload,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<String> get idempotencyKey => $state.composableBuilder(
      column: $state.table.idempotencyKey,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<int> get sequence => $state.composableBuilder(
      column: $state.table.sequence,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<DateTime> get createdAt => $state.composableBuilder(
      column: $state.table.createdAt,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<String> get tripId => $state.composableBuilder(
      column: $state.table.tripId,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<int> get attempts => $state.composableBuilder(
      column: $state.table.attempts,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<String> get lastFailure => $state.composableBuilder(
      column: $state.table.lastFailure,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));

  ColumnOrderings<bool> get isCompound => $state.composableBuilder(
      column: $state.table.isCompound,
      builder: (column, joinBuilders) =>
          ColumnOrderings(column, joinBuilders: joinBuilders));
}

class $$QueuedActionsTableTableManager extends RootTableManager<
    _$SyncDatabase,
    $QueuedActionsTable,
    QueuedActionRow,
    $$QueuedActionsTableFilterComposer,
    $$QueuedActionsTableOrderingComposer,
    $$QueuedActionsTableCreateCompanionBuilder,
    $$QueuedActionsTableUpdateCompanionBuilder,
    (
      QueuedActionRow,
      BaseReferences<_$SyncDatabase, $QueuedActionsTable, QueuedActionRow>
    ),
    QueuedActionRow,
    PrefetchHooks Function()> {
  $$QueuedActionsTableTableManager(_$SyncDatabase db, $QueuedActionsTable table)
      : super(TableManagerState(
          db: db,
          table: table,
          filteringComposer:
              $$QueuedActionsTableFilterComposer(ComposerState(db, table)),
          orderingComposer:
              $$QueuedActionsTableOrderingComposer(ComposerState(db, table)),
          updateCompanionCallback: ({
            Value<String> id = const Value.absent(),
            Value<String> kind = const Value.absent(),
            Value<String> payload = const Value.absent(),
            Value<String> idempotencyKey = const Value.absent(),
            Value<int> sequence = const Value.absent(),
            Value<DateTime> createdAt = const Value.absent(),
            Value<String?> tripId = const Value.absent(),
            Value<int> attempts = const Value.absent(),
            Value<String?> lastFailure = const Value.absent(),
            Value<bool> isCompound = const Value.absent(),
            Value<int> rowid = const Value.absent(),
          }) =>
              QueuedActionsCompanion(
            id: id,
            kind: kind,
            payload: payload,
            idempotencyKey: idempotencyKey,
            sequence: sequence,
            createdAt: createdAt,
            tripId: tripId,
            attempts: attempts,
            lastFailure: lastFailure,
            isCompound: isCompound,
            rowid: rowid,
          ),
          createCompanionCallback: ({
            required String id,
            required String kind,
            required String payload,
            required String idempotencyKey,
            required int sequence,
            required DateTime createdAt,
            Value<String?> tripId = const Value.absent(),
            Value<int> attempts = const Value.absent(),
            Value<String?> lastFailure = const Value.absent(),
            Value<bool> isCompound = const Value.absent(),
            Value<int> rowid = const Value.absent(),
          }) =>
              QueuedActionsCompanion.insert(
            id: id,
            kind: kind,
            payload: payload,
            idempotencyKey: idempotencyKey,
            sequence: sequence,
            createdAt: createdAt,
            tripId: tripId,
            attempts: attempts,
            lastFailure: lastFailure,
            isCompound: isCompound,
            rowid: rowid,
          ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ));
}

typedef $$QueuedActionsTableProcessedTableManager = ProcessedTableManager<
    _$SyncDatabase,
    $QueuedActionsTable,
    QueuedActionRow,
    $$QueuedActionsTableFilterComposer,
    $$QueuedActionsTableOrderingComposer,
    $$QueuedActionsTableCreateCompanionBuilder,
    $$QueuedActionsTableUpdateCompanionBuilder,
    (
      QueuedActionRow,
      BaseReferences<_$SyncDatabase, $QueuedActionsTable, QueuedActionRow>
    ),
    QueuedActionRow,
    PrefetchHooks Function()>;

class $SyncDatabaseManager {
  final _$SyncDatabase _db;
  $SyncDatabaseManager(this._db);
  $$QueuedActionsTableTableManager get queuedActions =>
      $$QueuedActionsTableTableManager(_db, _db.queuedActions);
}
