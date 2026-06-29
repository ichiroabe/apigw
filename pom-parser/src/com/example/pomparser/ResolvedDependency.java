package com.example.pomparser;

/**
 * 解決後の依存（ライブラリとバージョン）。
 * version は ${...} 展開・親/dependencyManagement/BOM インポート解決まで
 * 行った最終値。
 */
final class ResolvedDependency {
    final String groupId;
    final String artifactId;
    final String rawVersion;       // pom に書かれていた生の値（null 可）
    final String resolvedVersion;  // 解決後の値（解決不能なら null）
    final String scope;
    final String origin;           // バージョンの出所（説明用）

    /**
     * 未解決(resolvedVersion==null)のとき、親 / BOM までスキャンツリー内に
     * 揃っているのに解決できなかった = Maven ならビルドエラーになるはずの状態。
     * true ならツール側の取りこぼし、または pom 異常の疑い。
     */
    final boolean suspect;

    ResolvedDependency(String groupId, String artifactId, String rawVersion,
                       String resolvedVersion, String scope, String origin,
                       boolean suspect) {
        this.groupId = groupId;
        this.artifactId = artifactId;
        this.rawVersion = rawVersion;
        this.resolvedVersion = resolvedVersion;
        this.scope = scope;
        this.origin = origin;
        this.suspect = suspect;
    }

    boolean isResolved() {
        return resolvedVersion != null && resolvedVersion.indexOf("${") < 0;
    }
}
