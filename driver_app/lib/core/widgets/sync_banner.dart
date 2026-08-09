import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../l10n/app_localizations.dart';
import '../icons/app_icons.dart';
import '../sync/sync_cubit.dart';
import 'persistent_banner.dart';

/// C3 — what the queue is doing, under the app bar.
///
/// Distinct from C2, the offline banner, and both can be true at once: a driver
/// in a tunnel is offline *and* holding twenty unsent fixes, and collapsing
/// those into one message would drop whichever the driver most needs.
///
/// Says "waiting to send", never "failed to send", while an action is merely
/// queued. Nothing here is lost, and telling a driver otherwise would send them
/// to re-do work the phone is already holding.
class SyncBanner extends StatelessWidget {
  const SyncBanner({super.key});

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return BlocBuilder<SyncCubit, SyncState>(
      builder: (context, state) {
        return switch (state) {
          SyncEmpty() => const SizedBox.shrink(),
          SyncPending(:final count) => PersistentBanner(
              severity: BannerSeverity.info,
              icon: AppIcon.sync,
              message: strings.syncPending(count),
            ),
          SyncSyncing(:final remaining) => PersistentBanner(
              severity: BannerSeverity.info,
              icon: AppIcon.sync,
              message: strings.syncSending(remaining),
            ),
          // The one state that asks something of the driver. The count is all
          // the banner carries; the reasons live in the queue screen, in the
          // server's own words.
          SyncPartial(:final failed) => PersistentBanner(
              severity: BannerSeverity.caution,
              icon: AppIcon.warning,
              message: strings.syncFailed(failed),
              action: TextButton(
                onPressed: () => context.read<SyncCubit>().retryFailed(),
                child: Text(strings.syncRetry),
              ),
            ),
        };
      },
    );
  }
}
