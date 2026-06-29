package com.example.pomparser;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * パース済みの Pom 群に対し、親子関係・プロパティ・dependencyManagement を
 * 考慮してライブラリとバージョンを解決する。
 *
 * 解決の優先順位（バージョン）:
 *   1. dependency に直接書かれた version（${...} 展開）
 *   2. 自身～親チェーンの dependencyManagement の version（${...} 展開）
 * プロパティは「親を先に読み、子で上書き」した実効プロパティ表を用いる。
 */
final class PomResolver {

    /** GA キー -> Pom（親解決用インデックス）。 */
    private final Map<String, Pom> byGa = new LinkedHashMap<String, Pom>();
    /** GAV キー -> Pom（親解決用インデックス）。 */
    private final Map<String, Pom> byGav = new LinkedHashMap<String, Pom>();

    PomResolver(List<Pom> poms) {
        for (Pom p : poms) {
            Coordinate c = p.coordinate();
            if (c.ga().length() > 1) byGa.put(c.ga(), p);
            byGav.put(c.gav(), p);
        }
        for (Pom p : poms) {
            p.resolvedParent = findParent(p);
        }
    }

    /** 親 Pom をスキャン結果から解決（GAV 優先、無ければ GA）。 */
    private Pom findParent(Pom p) {
        if (p.parentCoordinate == null) return null;
        Pom byVer = byGav.get(p.parentCoordinate.gav());
        if (byVer != null && byVer != p) return byVer;
        Pom byKey = byGa.get(p.parentCoordinate.ga());
        if (byKey != null && byKey != p) return byKey;
        return null;
    }

    /**
     * 実効プロパティ表を構築する。
     * 親チェーンを根（最上位の親）から順に適用し、子で上書きする = 子優先。
     * さらに project.* の組み込みプロパティを付与する。
     */
    Map<String, String> effectiveProperties(Pom pom) {
        Map<String, String> result = new LinkedHashMap<String, String>();

        // 親チェーンを根から子の順に並べる
        List<Pom> chain = new ArrayList<Pom>();
        Pom cur = pom;
        int guard = 0;
        while (cur != null && guard++ < 100) {
            chain.add(0, cur); // 先頭に挿入 -> 根が先頭に来る
            cur = cur.resolvedParent;
        }

        // 根 -> 子 の順にプロパティを適用（子が後勝ち）
        for (Pom p : chain) {
            result.putAll(p.properties);
        }

        // 組み込みプロパティ（自身の値を優先）
        putIfNotNull(result, "project.groupId", pom.effectiveGroupId());
        putIfNotNull(result, "project.artifactId", pom.effectiveArtifactId());
        putIfNotNull(result, "project.version", pom.effectiveVersion());
        putIfNotNull(result, "pom.groupId", pom.effectiveGroupId());
        putIfNotNull(result, "pom.artifactId", pom.effectiveArtifactId());
        putIfNotNull(result, "pom.version", pom.effectiveVersion());
        if (pom.parentCoordinate != null) {
            putIfNotNull(result, "project.parent.groupId", pom.parentCoordinate.groupId);
            putIfNotNull(result, "project.parent.artifactId", pom.parentCoordinate.artifactId);
            putIfNotNull(result, "project.parent.version", pom.parentCoordinate.version);
        }
        return result;
    }

    private static void putIfNotNull(Map<String, String> m, String k, String v) {
        if (v != null) m.put(k, v);
    }

    /**
     * 自身～親チェーンの dependencyManagement をマージした表（GA -> Dependency）。
     * 子が後勝ち（子の dependencyManagement が親を上書き）。
     */
    Map<String, Dependency> effectiveDependencyManagement(Pom pom) {
        Map<String, Dependency> dm = new LinkedHashMap<String, Dependency>();
        List<Pom> chain = new ArrayList<Pom>();
        Pom cur = pom;
        int guard = 0;
        while (cur != null && guard++ < 100) {
            chain.add(0, cur);
            cur = cur.resolvedParent;
        }
        for (Pom p : chain) {
            for (Dependency d : p.dependencyManagement) {
                dm.put(d.ga(), d);
            }
        }
        return dm;
    }

    /** 1 つの pom の依存を解決してリストで返す。 */
    List<ResolvedDependency> resolveDependencies(Pom pom) {
        Map<String, String> props = effectiveProperties(pom);
        Map<String, Dependency> dm = effectiveDependencyManagement(pom);

        List<ResolvedDependency> out = new ArrayList<ResolvedDependency>();
        for (Dependency d : pom.dependencies) {
            String groupId = substitute(d.groupId, props);
            String artifactId = substitute(d.artifactId, props);
            String scope = substitute(d.scope, props);

            String rawVersion = d.version;
            String resolved;
            String origin;

            if (d.version != null) {
                resolved = substitute(d.version, props);
                origin = "dependency";
            } else {
                Dependency managed = dm.get(d.ga());
                if (managed != null && managed.version != null) {
                    rawVersion = managed.version;
                    resolved = substitute(managed.version, props);
                    origin = "dependencyManagement";
                } else {
                    resolved = null;
                    origin = "unresolved";
                }
                if (scope == null && managed != null) {
                    scope = substitute(managed.scope, props);
                }
            }
            out.add(new ResolvedDependency(groupId, artifactId, rawVersion, resolved, scope, origin));
        }
        return out;
    }

    /**
     * ${...} プロパティ参照を再帰的に置換する。
     * 循環参照・未定義プロパティは元の ${...} を残す。
     */
    static String substitute(String value, Map<String, String> props) {
        if (value == null) return null;
        return substitute(value, props, 0);
    }

    private static String substitute(String value, Map<String, String> props, int depth) {
        if (value == null || depth > 20 || value.indexOf("${") < 0) return value;
        StringBuilder sb = new StringBuilder();
        int i = 0;
        while (i < value.length()) {
            int start = value.indexOf("${", i);
            if (start < 0) {
                sb.append(value.substring(i));
                break;
            }
            int end = value.indexOf('}', start + 2);
            if (end < 0) {
                sb.append(value.substring(i));
                break;
            }
            sb.append(value, i, start);
            String key = value.substring(start + 2, end);
            String replacement = props.get(key);
            if (replacement == null) {
                replacement = systemFallback(key);
            }
            if (replacement == null) {
                sb.append(value, start, end + 1); // 未解決はそのまま残す
            } else {
                sb.append(substitute(replacement, props, depth + 1));
            }
            i = end + 1;
        }
        return sb.toString();
    }

    /** ${java.version} などシステムプロパティ/環境変数のフォールバック。 */
    private static String systemFallback(String key) {
        if (key.startsWith("env.")) {
            return System.getenv(key.substring(4));
        }
        return System.getProperty(key);
    }
}
