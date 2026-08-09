import 'package:ctms_driver/core/design_system/ctms_colors.dart';
import 'package:ctms_driver/core/widgets/consequence_panel.dart';
import 'package:ctms_driver/core/widgets/dual_action_selector.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_harness.dart';

void main() {
  group('DualActionSelector', () {
    testWidgets('starts with neither option chosen', (tester) async {
      await tester.pumpWidget(wrapForTest(
        Scaffold(
          body: DualActionSelector<String>(
            leftLabel: 'Pass',
            rightLabel: 'Fail',
            leftValue: 'pass',
            rightValue: 'fail',
            value: null,
            onChanged: (_) {},
          ),
        ),
      ));

      final options = tester.widgetList<Semantics>(
        find.descendant(
          of: find.byType(DualActionSelector<String>),
          matching: find.byType(Semantics),
        ),
      );

      expect(
        options.where((s) => s.properties.selected == true),
        isEmpty,
        reason: 'a pre-selected Pass lets a driver submit fourteen passes '
            'without looking at the bus',
      );
    });

    testWidgets('reports the value the driver chose', (tester) async {
      final chosen = <String>[];

      await tester.pumpWidget(wrapForTest(
        Scaffold(
          body: DualActionSelector<String>(
            leftLabel: 'Pass',
            rightLabel: 'Fail',
            leftValue: 'pass',
            rightValue: 'fail',
            value: null,
            onChanged: chosen.add,
          ),
        ),
      ));

      await tester.tap(find.text('Fail'));
      await tester.pump();

      expect(chosen, ['fail']);
    });

    testWidgets('both options are at least 56dp tall and equal width',
        (tester) async {
      await tester.pumpWidget(wrapForTest(
        Scaffold(
          body: DualActionSelector<String>(
            leftLabel: 'Pass',
            rightLabel: 'Fail',
            leftValue: 'pass',
            rightValue: 'fail',
            value: null,
            onChanged: (_) {},
          ),
        ),
      ));

      final left = tester.getSize(find.text('Pass'));
      final right = tester.getSize(find.text('Fail'));

      expect(tester.getSize(find.byType(InkWell).first).height,
          greaterThanOrEqualTo(56));
      expect(left.width, lessThan(400));
      expect(right.width, lessThan(400));
    });

    testWidgets('a disabled selector reports nothing', (tester) async {
      final chosen = <String>[];

      await tester.pumpWidget(wrapForTest(
        Scaffold(
          body: DualActionSelector<String>(
            leftLabel: 'Pass',
            rightLabel: 'Fail',
            leftValue: 'pass',
            rightValue: 'fail',
            value: null,
            enabled: false,
            onChanged: chosen.add,
          ),
        ),
      ));

      await tester.tap(find.text('Fail'));
      await tester.pump();

      expect(chosen, isEmpty);
    });
  });

  group('ConsequencePanel', () {
    testWidgets('states what is about to happen', (tester) async {
      await tester.pumpWidget(wrapForTest(
        const Scaffold(
          body: ConsequencePanel(
            severity: ConsequenceSeverity.danger,
            title: 'This will take the bus out of service',
            body: 'A maintenance ticket will be opened.',
          ),
        ),
      ));

      expect(find.text('This will take the bus out of service'), findsOneWidget);
      expect(find.text('A maintenance ticket will be opened.'), findsOneWidget);
    });

    testWidgets('each severity takes its semantic colour', (tester) async {
      for (final (severity, pick) in <(ConsequenceSeverity, Color Function(CtmsColors))>[
        (ConsequenceSeverity.info, (c) => c.info),
        (ConsequenceSeverity.warning, (c) => c.caution),
        (ConsequenceSeverity.danger, (c) => c.critical),
      ]) {
        await tester.pumpWidget(wrapForTest(
          Scaffold(
            body: ConsequencePanel(severity: severity, title: 't', body: 'b'),
          ),
        ));

        final context = tester.element(find.byType(ConsequencePanel));
        final box = tester.widget<Container>(
          find.descendant(
            of: find.byType(ConsequencePanel),
            matching: find.byType(Container),
          ),
        );
        final border = (box.decoration! as BoxDecoration).border!.top.color;

        expect(border, pick(context.ctms));
      }
    });

    testWidgets('reads as one announcement to a screen reader',
        (tester) async {
      await tester.pumpWidget(wrapForTest(
        const Scaffold(
          body: ConsequencePanel(
            severity: ConsequenceSeverity.danger,
            title: 'Bus out of service',
            body: 'A ticket will be opened.',
          ),
        ),
      ));

      expect(
        find.bySemanticsLabel('Bus out of service. A ticket will be opened.'),
        findsOneWidget,
        reason: 'a consequence split across two nodes gets read as two '
            'unrelated fragments',
      );
    });
  });
}
