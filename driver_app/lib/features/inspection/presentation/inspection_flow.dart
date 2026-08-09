import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../domain/inspection_state.dart';
import 'bloc/inspection_bloc.dart';
import 'inspection_result_screen.dart';
import 'inspection_review_screen.dart';
import 'inspection_screen.dart';

/// The inspection, as one destination.
///
/// P9, P10 and P11 are the same journey seen at three points, and M2 already
/// says which one the driver is at. Driving them from the state rather than
/// from a navigator stack means system back and "Back to checklist" cannot
/// disagree with the bloc about where the driver is — which is how a review
/// screen ends up sitting on top of a submitted inspection.
class InspectionFlow extends StatelessWidget {
  const InspectionFlow({required this.onFinished, super.key});

  /// Leaves the flow. The trip is re-read on the way out, because the bus's
  /// clearance has almost certainly just changed.
  final VoidCallback onFinished;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<InspectionBloc, InspectionState>(
      builder: (context, state) {
        return switch (state) {
          InspectionSubmitted(:final result) => InspectionResultScreen(
              result: result,
              onDone: onFinished,
            ),
          InspectionReviewing() ||
          InspectionSubmitting() ||
          InspectionSaved() =>
            const InspectionReviewScreen(),
          _ => const InspectionScreen(),
        };
      },
    );
  }
}
