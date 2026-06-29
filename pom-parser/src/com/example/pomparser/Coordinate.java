package com.example.pomparser;

/**
 * Maven 座標 (groupId:artifactId:version)。
 * version は null 可（親や dependencyManagement から解決されるケースがあるため）。
 */
final class Coordinate {
    final String groupId;
    final String artifactId;
    final String version;

    Coordinate(String groupId, String artifactId, String version) {
        this.groupId = groupId;
        this.artifactId = artifactId;
        this.version = version;
    }

    /** groupId:artifactId のキー（version を無視した同一性判定用）。 */
    String ga() {
        return n(groupId) + ":" + n(artifactId);
    }

    /** groupId:artifactId:version のキー。 */
    String gav() {
        return n(groupId) + ":" + n(artifactId) + ":" + n(version);
    }

    private static String n(String s) {
        return s == null ? "" : s;
    }

    @Override
    public String toString() {
        return gav();
    }
}
