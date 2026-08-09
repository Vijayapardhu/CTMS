/// Inspection payloads in the shape the backend actually returns.
///
/// Captured from a live `GET /inspections/checklist` and a real 201 from
/// `POST /buses/{id}/inspections` — fourteen items, `{item, label,
/// safety_critical}`, and an outcome the server decides.
library;

/// The fourteen items, in the server's own order.
const _checklist = <(String, String, bool)>[
  ('BRAKES', 'Brakes', true),
  ('TYRES', 'Tyres and pressure', true),
  ('LIGHTS', 'Lights and indicators', true),
  ('STEERING', 'Steering', true),
  ('DOORS', 'Doors', true),
  ('EMERGENCY_EXIT', 'Emergency exit', true),
  ('FIRE_EXTINGUISHER', 'Fire extinguisher', true),
  ('FIRST_AID_KIT', 'First aid kit', false),
  ('MIRRORS', 'Mirrors', false),
  ('HORN', 'Horn', false),
  ('WIPERS', 'Wipers', false),
  ('FLUID_LEVELS', 'Fluid levels', false),
  ('FUEL_LEVEL', 'Fuel level', false),
  ('CLEANLINESS', 'Cleanliness', false),
];

Map<String, dynamic> checklistResponse({List<Map<String, dynamic>>? items}) {
  return {
    'success': true,
    'message': 'Checklist retrieved successfully.',
    'code': 200,
    'data': items ??
        [
          for (final (code, label, critical) in _checklist)
            {'item': code, 'label': label, 'safety_critical': critical},
        ],
  };
}

/// The 201 from a submission. `outcome` is the server's decision.
Map<String, dynamic> submissionResponse({
  String outcome = 'PASSED',
  String? ticket,
}) {
  return {
    'success': true,
    'message': 'Inspection recorded.',
    'code': 201,
    'data': {
      'id': 'inspection-1',
      'bus_id': 'bus-1',
      'driver_id': 'driver-1',
      'outcome': outcome,
      'odometer_reading': 45200,
      'notes': null,
      'maintenance_ticket_id': ticket,
      'items': const [],
      'maintenance_ticket': null,
    },
  };
}

/// `App\Support\ApiError::response()`.
Map<String, dynamic> errorEnvelope(String message, {Map<String, dynamic>? errors}) {
  return {
    'success': false,
    'message': message,
    'data': null,
    if (errors != null) 'errors': errors,
  };
}
