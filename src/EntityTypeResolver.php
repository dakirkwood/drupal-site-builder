<?php

declare(strict_types=1);

namespace Drupal\drupal_site_builder;

/**
 * Maps Build Spec entity labels to Drupal entity type IDs.
 *
 * The Build Spec uses human-readable entity labels — "Content type",
 * "Vocabulary", "Media type", "Paragraph type", and friends — in several
 * columns. Multiple record
 * classes used to duplicate this mapping; centralising it here keeps the
 * vocabulary in a single place and makes it cheap to add or rename a type.
 *
 * The reference-bundle column (used by FieldRecord) carries the same labels
 * verbatim, so this resolver handles both exact-match and substring-match
 * lookups via two distinct entry points.
 */
final class EntityTypeResolver {

  /**
   * Resolves a Build Spec entity-type label by exact match.
   *
   * Used by record classes whose CSV column holds the label alone (for example
   * BundleRecord's "Type" column).
   *
   * @param string $label
   *   The Build Spec label, for example "Content type" or "Vocabulary".
   *
   * @return string
   *   The Drupal entity type ID (for example "node", "taxonomy_term"), or an
   *   empty string when the label is unrecognised. Callers are expected to
   *   handle the empty-string case loudly.
   */
  public static function fromLabel(string $label): string {
    return match ($label) {
      'Content type' => 'node',
      'Custom block type', 'Block type' => 'block_content',
      'Media type' => 'media',
      'Paragraph type' => 'paragraph',
      'User', 'User role' => 'user',
      'Vocabulary' => 'taxonomy_term',
      default => '',
    };
  }

  /**
   * Resolves a Build Spec entity-type label by substring match.
   *
   * Used by record classes whose CSV column embeds the label inside other
   * text (for example FieldRecord's "Bundle" column carries values like
   * "Page (Content type)"). The order of checks is significant: more specific
   * tokens must be tested before more general ones.
   *
   * @param string $haystack
   *   The CSV column value that may contain a Build Spec entity-type label.
   *
   * @return string
   *   The Drupal entity type ID, or an empty string when no label is found.
   */
  public static function fromHaystack(string $haystack): string {
    return match (TRUE) {
      str_contains($haystack, 'Content type') => 'node',
      str_contains($haystack, 'block type') => 'block_content',
      // The Build Spec includes a "Social Media Links" bundle on the User
      // entity; exclude it before the broad "Media" match below.
      str_contains($haystack, 'Media')
        && !str_contains($haystack, 'Social Media Links') => 'media',
      str_contains($haystack, 'Paragraph') => 'paragraph',
      str_contains($haystack, 'User') => 'user',
      str_contains($haystack, 'Vocabulary') => 'taxonomy_term',
      default => '',
    };
  }

}
