package com.example.pomparser;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * パース済みの Pom 群に対し、親子関係・プロパティ・dependencyManagement・
 * BOM インポートを考慮してライブラリとバージョンを解決する。
 *
 * 解決の優先順位（バージョン）:
 *   1. dependency に直接書かれた version（${...} 展開）
 *   2. 自身～親チェーンの dependencyManagement の version（${...} 展開）
 *      - うち BOM インポート (type=pom, scope=import) は、ツリー内の BOM を
 *        その BOM 自身のプロパティで解決して取り込む（明示宣言が優先）。
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

    /** dependencyManagement の 1 エントリの解決状態。 */
    private static final class ManagedEntry {
        final String scope;
        final String rawVersion;       // 明示エントリの生 version（後で消費側 props で展開）
        final String resolvedVersion;  // BOM 由来などの確定 version
        final boolean preResolved;     // true なら resolvedVersion を使う
        final String origin;

        ManagedEntry(String scope, String rawVersion, String resolvedVersion,
                     boolean preResolved, String origin) {
            this.scope = scope;
            this.rawVersion = rawVersion;
            this.resolvedVersion = resolvedVersion;
            this.preResolved = preResolved;
            this.origin = origin;
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

    /** 親チェーンを根（最上位）→自身の順に並べて返す。 */
    private List<Pom> chainRootFirst(Pom pom) {
        List<Pom> chain = new ArrayList<Pom>();
        Pom cur = pom;
        int guard = 0;
        while (cur != null && guard++ < 100) {
            chain.add(0, cur);
            cur = cur.resolvedParent;
        }
        return chain;
    }

    /**
     * 実効プロパティ表を構築する。
     * 親チェーンを根から順に適用し子で上書き（子優先）。project.* 等も付与。
     */
    Map<String, String> effectiveProperties(Pom pom) {
        Map<String, String> result = new LinkedHashMap<String, String>();
        for (Pom p : chainRootFirst(pom)) {
            result.putAll(p.properties);
        }
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

    private static boolean isBomImport(Dependency d) {
        return "import".equals(d.scope) && "pom".equals(d.type);
    }

    /**
     * 自身～親チェーンの dependencyManagement をマージした表（GA -> ManagedEntry）。
     * BOM インポートは展開して取り込み、明示宣言が優先（インポートより上位）。
     * 子が親を上書きする。
     */
    private Map<String, ManagedEntry> effectiveDependencyManagement(Pom pom, Set<String> visiting) {
        Map<String, ManagedEntry> imported = new LinkedHashMap<String, ManagedEntry>();
        Map<String, ManagedEntry> explicit = new LinkedHashMap<String, ManagedEntry>();

        for (Pom p : chainRootFirst(pom)) {
            Map<String, String> propsP = effectiveProperties(p);
            for (Dependency d : p.dependencyManagement) {
                if (isBomImport(d)) {
                    String g = substitute(d.groupId, propsP);
                    String a = substitute(d.artifactId, propsP);
                    String v = substitute(d.version, propsP);
                    String gav = (g == null ? "" : g) + ":" + (a == null ? "" : a) + ":" + (v == null ? "" : v);
                    if (visiting.contains(gav)) continue; // 循環
                    Pom bom = byGav.get(gav);
                    if (bom == null) bom = byGa.get((g == null ? "" : g) + ":" + (a == null ? "" : a));
                    if (bom == null) continue; // ツリー外 BOM は取り込めない
                    visiting.add(gav);
                    Map<String, ManagedEntry> bomDm = resolvedDependencyManagement(bom, visiting);
                    visiting.remove(gav);
                    String label = "bomImport:" + bom.coordinate().gav();
                    for (Map.Entry<String, ManagedEntry> e : bomDm.entrySet()) {
                        ManagedEntry me = e.getValue();
                        imported.put(e.getKey(),
                                new ManagedEntry(me.scope, null, me.resolvedVersion, true, label));
                    }
                } else {
                    explicit.put(d.ga(),
                            new ManagedEntry(d.scope, d.version, null, false, "dependencyManagement"));
                }
            }
        }
        Map<String, ManagedEntry> result = new LinkedHashMap<String, ManagedEntry>(imported);
        result.putAll(explicit); // 明示宣言がインポートを上書き
        return result;
    }

    /**
     * BOM 取り込み用: 当該 pom の dependencyManagement を「その pom 自身の
     * プロパティ」で確定値まで解決して返す。
     */
    private Map<String, ManagedEntry> resolvedDependencyManagement(Pom bom, Set<String> visiting) {
        Map<String, ManagedEntry> dm = effectiveDependencyManagement(bom, visiting);
        Map<String, String> propsBom = effectiveProperties(bom);
        Map<String, ManagedEntry> out = new LinkedHashMap<String, ManagedEntry>();
        for (Map.Entry<String, ManagedEntry> e : dm.entrySet()) {
            ManagedEntry me = e.getValue();
            if (me.preResolved) {
                out.put(e.getKey(), me);
            } else {
                out.put(e.getKey(), new ManagedEntry(
                        me.scope, null, substitute(me.rawVersion, propsBom), true, me.origin));
            }
        }
        return out;
    }

    /** 1 つの pom の依存を解決してリストで返す。 */
    List<ResolvedDependency> resolveDependencies(Pom pom) {
        Map<String, String> props = effectiveProperties(pom);
        Map<String, ManagedEntry> dm = effectiveDependencyManagement(pom, new HashSet<String>());
        boolean external = dependsOnOutsideTree(pom);

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
                ManagedEntry me = dm.get(d.ga());
                if (me != null && me.preResolved && me.resolvedVersion != null) {
                    resolved = me.resolvedVersion;
                    origin = me.origin;
                } else if (me != null && me.rawVersion != null) {
                    rawVersion = me.rawVersion;
                    resolved = substitute(me.rawVersion, props);
                    origin = me.origin;
                } else {
                    resolved = null;
                    origin = external ? "unresolved(external?)" : "unresolved(tree-complete!)";
                }
                if (scope == null && me != null) {
                    scope = me.scope;
                }
            }

            boolean unresolved = (resolved == null) || resolved.indexOf("${") >= 0;
            // ツリー内に親/BOM が揃っているのに未解決 = Maven ならエラーのはずの疑わしい状態
            boolean suspect = unresolved && !external;
            if (unresolved && resolved != null) {
                // ${...} が残った = プロパティ未定義。ツリー完結なら疑わしい
                origin = external ? "unresolved(external?)" : "unresolved(tree-complete!)";
            }
            out.add(new ResolvedDependency(groupId, artifactId, rawVersion, resolved, scope, origin, suspect));
        }
        return out;
    }

    /**
     * この pom がスキャンツリーの外に依存解決を頼っている可能性があるか。
     * - 親チェーンの最上位がまだ外部親を持つ（親がツリー外）
     * - BOM インポートでツリー外の BOM を参照している
     * いずれかなら true（= 未解決でも「ツリー外なので仕方ない」と分類できる）。
     */
    private boolean dependsOnOutsideTree(Pom pom) {
        List<Pom> chain = chainRootFirst(pom);
        Pom root = chain.get(0);
        if (root.parentCoordinate != null && root.resolvedParent == null) {
            return true; // 最上位の親がツリー外
        }
        return hasMissingBomImport(pom, new HashSet<String>());
    }

    /** 親チェーン上の BOM インポートに、ツリー内で見つからないものがあるか。 */
    private boolean hasMissingBomImport(Pom pom, Set<String> visiting) {
        for (Pom p : chainRootFirst(pom)) {
            Map<String, String> propsP = effectiveProperties(p);
            for (Dependency d : p.dependencyManagement) {
                if (!isBomImport(d)) continue;
                String g = substitute(d.groupId, propsP);
                String a = substitute(d.artifactId, propsP);
                String v = substitute(d.version, propsP);
                String gav = (g == null ? "" : g) + ":" + (a == null ? "" : a) + ":" + (v == null ? "" : v);
                if (visiting.contains(gav)) continue;
                Pom bom = byGav.get(gav);
                if (bom == null) bom = byGa.get((g == null ? "" : g) + ":" + (a == null ? "" : a));
                if (bom == null) return true;
                visiting.add(gav);
                boolean nested = hasMissingBomImport(bom, visiting);
                visiting.remove(gav);
                if (nested) return true;
            }
        }
        return false;
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
