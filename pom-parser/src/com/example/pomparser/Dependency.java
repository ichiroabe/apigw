package com.example.pomparser;

/**
 * pom.xml の dependency / dependencyManagement の 1 エントリ。
 * version は pom に直接書かれた生の値（${...} を含み得る、null 可）。
 */
final class Dependency {
    final String groupId;
    final String artifactId;
    final String version;   // 生の値（未解決、null 可）
    final String scope;
    final String type;
    final String optional;

    Dependency(String groupId, String artifactId, String version,
               String scope, String type, String optional) {
        this.groupId = groupId;
        this.artifactId = artifactId;
        this.version = version;
        this.scope = scope;
        this.type = type;
        this.optional = optional;
    }

    String ga() {
        return (groupId == null ? "" : groupId) + ":" + (artifactId == null ? "" : artifactId);
    }
}
