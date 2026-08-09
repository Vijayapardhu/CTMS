import 'package:flutter/material.dart';

import '../design_system/ctms_colors.dart';
import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

/// How loudly a banner speaks.
///
/// Three of the four semantic statuses; `neutral` is absent because a banner
/// that means nothing in particular is a banner that should not be shown.
enum BannerSeverity { info, caution, critical }

/// Component 14 — `PersistentBanner`.
///
/// A banner, not a snackbar. A snackbar disappears; the conditions this
/// announces do not. It sits directly under the app bar and **pushes content
/// down** rather than floating over it, so it can never cover the control a
/// driver was reaching for.
///
/// Not dismissible by default, and deliberately: dismissing an offline banner
/// does not reconnect anything, so the condition would still be true with the
/// only sign of it gone.
class PersistentBanner extends StatelessWidget {
  const PersistentBanner({
    required this.severity,
    required this.message,
    this.action,
    this.dismissible = false,
    this.onDismiss,
    this.icon,
    super.key,
  }) : assert(
          !dismissible || onDismiss != null,
          'A dismissible banner needs somewhere for the dismissal to go.',
        );

  final BannerSeverity severity;

  /// Shown verbatim. Where a banner carries a server message, that message is
  /// already written for drivers and must not be paraphrased.
  final String message;

  /// The one thing the driver can do about it, if there is one.
  final Widget? action;

  final bool dismissible;
  final VoidCallback? onDismiss;

  /// Overrides the icon the severity would otherwise choose.
  ///
  /// Severity picks a general glyph — a warning triangle for caution. Some
  /// conditions have a better one in the registry: connectivity loss owns
  /// `AppIcon.offline`, which says *why* rather than merely *how badly*.
  final AppIcon? icon;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;
    final theme = Theme.of(context);

    final (background, foreground, defaultIcon) = switch (severity) {
      BannerSeverity.info => (colors.info, colors.onInfo, AppIcon.info),
      BannerSeverity.caution => (colors.caution, colors.onCaution, AppIcon.warning),
      BannerSeverity.critical => (colors.critical, colors.onCritical, AppIcon.error),
    };

    return Material(
      color: background,
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: Spacing.md,
            vertical: Spacing.sm,
          ),
          child: Row(
            children: [
              // Never colour alone. Around one in twelve male drivers has a
              // colour vision deficiency, so the glyph carries the same
              // meaning the background does.
              AppIconView(
                icon ?? defaultIcon,
                size: IconSize.sm,
                color: foreground,
              ),
              const SizedBox(width: Spacing.sm),
              Expanded(
                child: Text(
                  message,
                  style: theme.textTheme.labelMedium?.copyWith(color: foreground),
                ),
              ),
              if (action != null) ...[
                const SizedBox(width: Spacing.sm),
                // Inherits the banner's foreground so an action on a coloured
                // ground keeps its contrast ratio.
                IconTheme.merge(
                  data: IconThemeData(color: foreground),
                  child: DefaultTextStyle.merge(
                    style: TextStyle(color: foreground),
                    child: action!,
                  ),
                ),
              ],
              if (dismissible) ...[
                const SizedBox(width: Spacing.xs),
                IconButton(
                  onPressed: onDismiss,
                  tooltip: MaterialLocalizations.of(context).closeButtonTooltip,
                  icon: AppIconView(
                    AppIcon.close,
                    size: IconSize.sm,
                    color: foreground,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Gives a [PersistentBanner] its arrival and departure.
///
/// Height expansion — the banner pushes the content below it down and pulls it
/// back up, which is what makes it read as part of the layout rather than
/// something floating over it.
///
/// The durations survive "reduce motion" on purpose. Everywhere else in this
/// app that flag takes durations to zero; here the design system keeps the
/// 200ms, because a banner that simply blinks into existence is a banner a
/// driver does not notice arriving. Appearing is the whole message.
class AnimatedPersistentBanner extends StatelessWidget {
  const AnimatedPersistentBanner({required this.visible, this.banner, super.key});

  final bool visible;

  /// Kept while collapsing so the banner animates out with its own words
  /// rather than vanishing and then shrinking an empty strip.
  final PersistentBanner? banner;

  @override
  Widget build(BuildContext context) {
    return AnimatedSize(
      duration: visible ? Motion.bannerIn : Motion.bannerOut,
      curve: Curves.easeOut,
      alignment: Alignment.topCenter,
      child: visible && banner != null
          ? SizedBox(width: double.infinity, child: banner)
          : const SizedBox(width: double.infinity, height: 0),
    );
  }
}
