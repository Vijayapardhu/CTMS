import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../app/di/service_locator.dart';
import '../../../app/router/routes.dart';
import '../../../core/api/api_client.dart';
import '../../../core/connectivity/connectivity_service.dart';
import '../../../core/services/logger_service.dart';
import '../../trip/presentation/bloc/trip_bloc.dart';
import '../data/inspection_api.dart';
import '../data/inspection_draft_store.dart';
import 'bloc/inspection_bloc.dart';
import 'inspection_flow.dart';

/// Builds the inspection flow and its bloc.
///
/// Flow-scoped, unlike the session, connectivity and trip blocs: an inspection
/// belongs to the screens the driver is standing in, and closing it should take
/// its in-memory state with it. The **draft** is what survives, and that lives
/// in storage rather than in the bloc.
class InspectionEntry extends StatelessWidget {
  const InspectionEntry({
    required this.busId,
    this.minimumOdometer,
    super.key,
  });

  final String busId;
  final int? minimumOdometer;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<InspectionBloc>(
      create: (_) => InspectionBloc(
        api: InspectionApi(sl<ApiClient>()),
        drafts: InspectionDraftStore(
          sl<SharedPreferences>(),
          sl<LoggerService>(),
        ),
        connectivity: sl<ConnectivityService>(),
      )..add(InspectionOpened(busId, minimumOdometer: minimumOdometer)),
      child: InspectionFlow(
        onFinished: () {
          // The clearance has almost certainly just changed, so the trip is
          // re-read rather than left showing the reasons that were true a
          // minute ago.
          context.read<TripBloc>().add(const TripRefreshed());
          context.goNamed(Routes.trip);
        },
      ),
    );
  }
}
