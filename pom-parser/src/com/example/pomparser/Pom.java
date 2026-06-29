package com.example.pomparser;

import java.io.File;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * 1 つの pom.xml をパースした生データ + 親への解決済み参照。
 * 「生データ」とは pom.xml にそのまま書かれている値で、プロパティ展開や
 * 親からの継承は適用されていない状態を指す。
 */
final class Pom {
    final File file;

    // <project> 直下の生の値（親から継承される場合 null のことがある）
    String groupId;
    String artifactId;
    String version;
    String packaging;

    // <parent> の座標と relativePath（無ければ null）
    Coordinate parentCoordinate;
    String parentRelativePath;

    // この pom 自身で定義されたプロパティ
    final Map<String, String> properties = new LinkedHashMap<String, String>();

    final List<Dependency> dependencies = new ArrayList<Dependency>();
    final List<Dependency> dependencyManagement = new ArrayList<Dependency>();

    // スキャン結果から解決した親 Pom（同一フォルダツリー内に存在する場合のみ）
    Pom resolvedParent;

    Pom(File file) {
        this.file = file;
    }

    /** 親から継承を考慮した実効 groupId。 */
    String effectiveGroupId() {
        if (groupId != null) return groupId;
        if (parentCoordinate != null) return parentCoordinate.groupId;
        return null;
    }

    /** 親から継承を考慮した実効 version。 */
    String effectiveVersion() {
        if (version != null) return version;
        if (parentCoordinate != null) return parentCoordinate.version;
        return null;
    }

    String effectiveArtifactId() {
        return artifactId;
    }

    Coordinate coordinate() {
        return new Coordinate(effectiveGroupId(), effectiveArtifactId(), effectiveVersion());
    }
}
