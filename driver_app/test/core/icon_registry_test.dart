import 'package:ctms_driver/core/icons/app_icons.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_harness.dart';

void main() {
  group('AppIcon registry', () {
    test('every icon resolves to a symbol that exists', () {
      for (final icon in AppIcon.all) {
        final glyph = icon.preferred;

        expect(
          glyph == null || glyph.isNotEmpty,
          isTrue,
          reason: '"${icon.semanticLabel}" has an empty Hugeicons symbol, '
              'which renders as nothing at all',
        );
      }
    });

    test('every icon has a non-empty semantic label', () {
      for (final icon in AppIcon.all) {
        expect(icon.semanticLabel.trim(), isNotEmpty);
      }
    });

    test('no two icons share a semantic label', () {
      final seen = <String, int>{};

      for (final icon in AppIcon.all) {
        seen.update(icon.semanticLabel, (n) => n + 1, ifAbsent: () => 1);
      }

      final duplicates = seen.entries.where((e) => e.value > 1).map((e) => e.key);

      expect(
        duplicates,
        isEmpty,
        reason: 'a screen reader announcing the same label for two different '
            'controls tells the driver nothing',
      );
    });

    testWidgets('renders the Hugeicons symbol when one is declared',
        (tester) async {
      await tester.pumpWidget(
        wrapForTest(const Center(child: AppIconView(AppIcon.trip))),
      );

      expect(find.byType(AppIconView), findsOneWidget);
      expect(find.byType(Icon), findsNothing);
    });

    testWidgets('falls back to the Material symbol when preferred is null',
        (tester) async {
      const missing = AppIcon.forTest(
        null,
        Icons.error_rounded,
        semanticLabel: 'Missing',
      );

      await tester.pumpWidget(
        wrapForTest(const Center(child: AppIconView(missing))),
      );

      expect(find.byIcon(Icons.error_rounded), findsOneWidget);
    });

    testWidgets('exposes its label to the semantics tree', (tester) async {
      await tester.pumpWidget(
        wrapForTest(const Center(child: AppIconView(AppIcon.sos))),
      );

      expect(
        tester.getSemantics(find.byType(AppIconView)),
        matchesSemantics(label: AppIcon.sos.semanticLabel, isImage: true),
      );
    });

    testWidgets('hides itself from semantics when asked', (tester) async {
      await tester.pumpWidget(
        wrapForTest(
          const Center(child: AppIconView(AppIcon.trip, excludeSemantics: true)),
        ),
      );

      expect(
        find.bySemanticsLabel(AppIcon.trip.semanticLabel),
        findsNothing,
        reason: 'a tab icon beside its own text label would be announced twice',
      );
    });
  });
}
