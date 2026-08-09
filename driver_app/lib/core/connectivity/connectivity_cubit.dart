import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import 'connectivity_service.dart';

/// M7 — connectivity.
///
/// Exactly the two states the machine defines, and no more. There is no
/// `checking` or `unknown`: the app is always willing to answer "can I reach
/// the API right now", and a third state would only ever be rendered as one of
/// these two anyway.
///
/// App-scoped and provided above the router, per the Bloc mapping in
/// `docs/driver-app/04-state-machines.md`. Every machine that follows —
/// trip, GPS, sync — subscribes to this one, so it must outlive any screen.
///
/// The Cubit owns no logic of its own. [ConnectivityService] decides what
/// reachable means, because that decision is made from inside the HTTP layer
/// where the failures actually happen. This is the seam that lets a widget ask
/// with a `BlocBuilder` instead of reaching into the service locator mid-build.
class ConnectivityCubit extends Cubit<Reachability> {
  ConnectivityCubit(ConnectivityService service) : super(service.current) {
    _subscription = service.changes.listen(emit);
  }

  late final StreamSubscription<Reachability> _subscription;

  /// Reads better than comparing against the enum at every call site, and
  /// keeps the sense of the test the same everywhere it is asked.
  bool get isOffline => state == Reachability.offline;

  @override
  Future<void> close() async {
    await _subscription.cancel();
    return super.close();
  }
}
