import java.io.File;
import java.io.PrintStream;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import javax.xml.parsers.DocumentBuilder;
import javax.xml.parsers.DocumentBuilderFactory;
import org.w3c.dom.Document;
import org.w3c.dom.Element;
import org.w3c.dom.Node;
import org.w3c.dom.NodeList;

/**
 * pom.xml 解析ツール（単一ファイル・パッケージ無し版）。
 *
 * 指定フォルダ以下を再帰的に走査し、見つかった pom.xml すべてについて
 * 親子関係・プロパティ・dependencyManagement・BOM インポートを考慮して
 * ライブラリ（groupId:artifactId）とバージョンを解決して出力する。
 *
 * JDK 1.8 標準ライブラリのみ使用（外部依存なし）。
 *
 * ビルド・実行:
 *   javac PomParser.java
 *   java  PomParser <フォルダ> [--csv] [--props] [--include-target] [--strict]
 *
 * JDK 11 以降なら single-file source-code で直接実行も可:
 *   java PomParser.java <フォルダ>
 */
public final class PomParser {

    public static void main(String[] args) {
        PrintStream out;
        try {
            out = new PrintStream(System.out, true, "UTF-8");
            System.setErr(new PrintStream(System.err, true, "UTF-8"));
        } catch (Exception e) {
            out = System.out;
        }

        if (args.length < 1) {
            System.err.println("使い方: java PomParser <フォルダ> [--csv] [--props] [--include-target] [--strict]");
            System.exit(2);
            return;
        }

        File root = new File(args[0]);
        boolean csv = false;
        boolean showProps = false;
        boolean includeTarget = false;
        boolean strict = false;
        for (int i = 1; i < args.length; i++) {
            if ("--csv".equals(args[i])) csv = true;
            else if ("--props".equals(args[i])) showProps = true;
            else if ("--include-target".equals(args[i])) includeTarget = true;
            else if ("--strict".equals(args[i])) strict = true;
            else {
                System.err.println("不明なオプション: " + args[i]);
                System.exit(2);
                return;
            }
        }

        if (!root.exists()) {
            System.err.println("指定フォルダが存在しません: " + root.getAbsolutePath());
            System.exit(1);
            return;
        }

        List<File> pomFiles = new ArrayList<File>();
        collectPoms(root, pomFiles, includeTarget);

        if (pomFiles.isEmpty()) {
            System.err.println("pom.xml が見つかりませんでした: " + root.getAbsolutePath());
            return;
        }

        // すべての pom を先読みしてから親子解決（親が子より後に見つかっても対応）
        PomReader reader = new PomReader();
        List<Pom> poms = new ArrayList<Pom>();
        for (File f : pomFiles) {
            try {
                poms.add(reader.read(f));
            } catch (Exception e) {
                System.err.println("解析失敗: " + f.getAbsolutePath() + " : " + e.getMessage());
            }
        }

        PomResolver resolver = new PomResolver(poms);

        // 全依存を一度解決し、集計（出力と strict 判定で共用）
        int total = 0;
        int unresolvedExternal = 0;
        int unresolvedSuspect = 0;
        for (Pom pom : poms) {
            for (ResolvedDependency d : resolver.resolveDependencies(pom)) {
                total++;
                if (!d.isResolved()) {
                    if (d.suspect) unresolvedSuspect++;
                    else unresolvedExternal++;
                }
            }
        }

        if (csv) {
            printCsv(out, poms, resolver);
        } else {
            printText(out, root, poms, resolver, showProps);
        }

        out.println();
        out.println("---- サマリ ----");
        out.println("  依存総数            : " + total);
        out.println("  解決済み            : " + (total - unresolvedExternal - unresolvedSuspect));
        out.println("  未解決(ツリー外)    : " + unresolvedExternal + "  ← 親/BOM がスキャン対象外。リモート取得が必要");
        out.println("  未解決(ツリー完結)  : " + unresolvedSuspect + "  ← 親/BOM は揃っているのに未解決。Maven ならエラーのはず（要確認）");
        out.flush();

        if (strict && unresolvedSuspect > 0) {
            System.err.println("strict: ツリー完結なのに未解決の依存が " + unresolvedSuspect
                    + " 件あります（Maven ならビルドエラーのはず）。");
            System.exit(3);
        }
    }

    /** 指定ディレクトリ配下を再帰し pom.xml を収集する。 */
    private static void collectPoms(File dir, List<File> out, boolean includeTarget) {
        if (dir.isFile()) {
            if (dir.getName().equals("pom.xml")) out.add(dir);
            return;
        }
        File[] children = dir.listFiles();
        if (children == null) return;
        for (File c : children) {
            if (c.isDirectory()) {
                String name = c.getName();
                if (name.equals(".git")) continue;
                if (!includeTarget && name.equals("target")) continue;
                collectPoms(c, out, includeTarget);
            } else if (c.getName().equals("pom.xml")) {
                out.add(c);
            }
        }
    }

    // ---- テキスト出力 ----

    private static void printText(PrintStream out, File root, List<Pom> poms,
                                  PomResolver resolver, boolean showProps) {
        out.println("====================================================");
        out.println(" pom.xml 解析結果");
        out.println(" ルート   : " + root.getAbsolutePath());
        out.println(" pom 件数 : " + poms.size());
        out.println("====================================================");

        for (Pom pom : poms) {
            out.println();
            out.println("■ プロジェクト: " + pom.coordinate().gav());
            out.println("  ファイル   : " + pom.file.getAbsolutePath());
            if (pom.packaging != null) out.println("  packaging  : " + pom.packaging);
            if (pom.parentCoordinate != null) {
                String parentStatus = (pom.resolvedParent != null)
                        ? "（ツリー内で解決済み）"
                        : "（外部親 / ツリー外）";
                out.println("  親         : " + pom.parentCoordinate.gav() + " " + parentStatus);
            }

            if (showProps) {
                Map<String, String> props = resolver.effectiveProperties(pom);
                out.println("  実効プロパティ:");
                for (Map.Entry<String, String> e : props.entrySet()) {
                    out.println("    " + e.getKey() + " = " + e.getValue());
                }
            }

            List<ResolvedDependency> deps = resolver.resolveDependencies(pom);
            if (deps.isEmpty()) {
                out.println("  依存       : なし");
            } else {
                out.println("  依存 (" + deps.size() + "):");
                for (ResolvedDependency d : deps) {
                    boolean ok = d.isResolved();
                    String ver = ok ? d.resolvedVersion : "(未解決)";
                    StringBuilder line = new StringBuilder();
                    line.append(d.suspect ? "    ! " : "    - ")
                        .append(d.groupId).append(":").append(d.artifactId)
                        .append(" : ").append(ver);
                    if (d.scope != null) line.append(" [").append(d.scope).append("]");
                    line.append("  <").append(d.origin).append(">");
                    if (ok && d.rawVersion != null && !d.resolvedVersion.equals(d.rawVersion)) {
                        line.append("  (raw: ").append(d.rawVersion).append(")");
                    }
                    out.println(line.toString());
                }
            }
        }
    }

    // ---- CSV 出力 ----

    private static void printCsv(PrintStream out, List<Pom> poms, PomResolver resolver) {
        out.println("project,pomFile,depGroupId,depArtifactId,resolvedVersion,rawVersion,scope,versionOrigin,resolved,suspect");
        for (Pom pom : poms) {
            String project = pom.coordinate().gav();
            String path = pom.file.getAbsolutePath();
            for (ResolvedDependency d : resolver.resolveDependencies(pom)) {
                out.println(csv(project) + "," + csv(path) + ","
                        + csv(d.groupId) + "," + csv(d.artifactId) + ","
                        + csv(d.isResolved() ? d.resolvedVersion : null) + "," + csv(d.rawVersion) + ","
                        + csv(d.scope) + "," + csv(d.origin) + ","
                        + d.isResolved() + "," + d.suspect);
            }
        }
    }

    private static String csv(String s) {
        if (s == null) return "";
        if (s.indexOf(',') >= 0 || s.indexOf('"') >= 0 || s.indexOf('\n') >= 0) {
            return "\"" + s.replace("\"", "\"\"") + "\"";
        }
        return s;
    }

    private PomParser() {
    }
}

// =====================================================================
//  以下、補助クラス（同一ファイル内・package-private）
// =====================================================================

/**
 * Maven 座標 (groupId:artifactId:version)。version は null 可。
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

    String ga() {
        return n(groupId) + ":" + n(artifactId);
    }

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

/**
 * pom.xml の dependency / dependencyManagement の 1 エントリ（生の値）。
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

/**
 * 1 つの pom.xml をパースした生データ + 親への解決済み参照。
 */
final class Pom {
    final File file;

    String groupId;
    String artifactId;
    String version;
    String packaging;

    Coordinate parentCoordinate;
    String parentRelativePath;

    final Map<String, String> properties = new LinkedHashMap<String, String>();
    final List<Dependency> dependencies = new ArrayList<Dependency>();
    final List<Dependency> dependencyManagement = new ArrayList<Dependency>();

    Pom resolvedParent;

    Pom(File file) {
        this.file = file;
    }

    String effectiveGroupId() {
        if (groupId != null) return groupId;
        if (parentCoordinate != null) return parentCoordinate.groupId;
        return null;
    }

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

/**
 * 解決後の依存（ライブラリとバージョン）。
 */
final class ResolvedDependency {
    final String groupId;
    final String artifactId;
    final String rawVersion;
    final String resolvedVersion;
    final String scope;
    final String origin;

    /**
     * 未解決のとき、親 / BOM までツリー内に揃っているのに解決できなかった
     * = Maven ならビルドエラーになるはずの疑わしい状態。
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

/**
 * 1 つの pom.xml を DOM で読み、生データを {@link Pom} に詰める。
 * JDK 標準の javax.xml.parsers のみを使用。
 */
final class PomReader {

    private final DocumentBuilderFactory factory;

    PomReader() {
        factory = DocumentBuilderFactory.newInstance();
        factory.setNamespaceAware(false);
        factory.setIgnoringComments(true);
        trySetFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        trySetFeature("http://xml.org/sax/features/external-general-entities", false);
        trySetFeature("http://xml.org/sax/features/external-parameter-entities", false);
    }

    private void trySetFeature(String name, boolean value) {
        try {
            factory.setFeature(name, value);
        } catch (Exception ignore) {
            // パーサ実装が当該機能を持たない場合は無視
        }
    }

    Pom read(File file) throws Exception {
        DocumentBuilder builder = factory.newDocumentBuilder();
        Document doc = builder.parse(file);
        Element project = doc.getDocumentElement();

        Pom pom = new Pom(file);
        pom.groupId = childText(project, "groupId");
        pom.artifactId = childText(project, "artifactId");
        pom.version = childText(project, "version");
        pom.packaging = childText(project, "packaging");

        Element parent = firstChildElement(project, "parent");
        if (parent != null) {
            pom.parentCoordinate = new Coordinate(
                    childText(parent, "groupId"),
                    childText(parent, "artifactId"),
                    childText(parent, "version"));
            pom.parentRelativePath = childText(parent, "relativePath");
        }

        Element props = firstChildElement(project, "properties");
        if (props != null) {
            NodeList kids = props.getChildNodes();
            for (int i = 0; i < kids.getLength(); i++) {
                Node n = kids.item(i);
                if (n.getNodeType() == Node.ELEMENT_NODE) {
                    Element e = (Element) n;
                    pom.properties.put(e.getTagName(), textOf(e));
                }
            }
        }

        Element deps = firstChildElement(project, "dependencies");
        if (deps != null) {
            readDependencies(deps, pom.dependencies);
        }

        Element depMgmt = firstChildElement(project, "dependencyManagement");
        if (depMgmt != null) {
            Element managed = firstChildElement(depMgmt, "dependencies");
            if (managed != null) {
                readDependencies(managed, pom.dependencyManagement);
            }
        }

        return pom;
    }

    private void readDependencies(Element dependenciesEl, List<Dependency> out) {
        for (Element dep : childElements(dependenciesEl, "dependency")) {
            out.add(new Dependency(
                    childText(dep, "groupId"),
                    childText(dep, "artifactId"),
                    childText(dep, "version"),
                    childText(dep, "scope"),
                    childText(dep, "type"),
                    childText(dep, "optional")));
        }
    }

    private static String childText(Element parent, String tag) {
        Element e = firstChildElement(parent, tag);
        if (e == null) return null;
        String t = textOf(e);
        return t == null ? null : t.trim();
    }

    private static String textOf(Element e) {
        StringBuilder sb = new StringBuilder();
        NodeList kids = e.getChildNodes();
        for (int i = 0; i < kids.getLength(); i++) {
            Node n = kids.item(i);
            if (n.getNodeType() == Node.TEXT_NODE || n.getNodeType() == Node.CDATA_SECTION_NODE) {
                sb.append(n.getNodeValue());
            }
        }
        return sb.toString().trim();
    }

    private static Element firstChildElement(Element parent, String tag) {
        NodeList kids = parent.getChildNodes();
        for (int i = 0; i < kids.getLength(); i++) {
            Node n = kids.item(i);
            if (n.getNodeType() == Node.ELEMENT_NODE && ((Element) n).getTagName().equals(tag)) {
                return (Element) n;
            }
        }
        return null;
    }

    private static List<Element> childElements(Element parent, String tag) {
        List<Element> list = new ArrayList<Element>();
        NodeList kids = parent.getChildNodes();
        for (int i = 0; i < kids.getLength(); i++) {
            Node n = kids.item(i);
            if (n.getNodeType() == Node.ELEMENT_NODE && ((Element) n).getTagName().equals(tag)) {
                list.add((Element) n);
            }
        }
        return list;
    }
}

/**
 * パース済みの Pom 群に対し、親子関係・プロパティ・dependencyManagement・
 * BOM インポートを考慮してライブラリとバージョンを解決する。
 */
final class PomResolver {

    private final Map<String, Pom> byGa = new LinkedHashMap<String, Pom>();
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
        final String rawVersion;
        final String resolvedVersion;
        final boolean preResolved;
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

    private Pom findParent(Pom p) {
        if (p.parentCoordinate == null) return null;
        Pom byVer = byGav.get(p.parentCoordinate.gav());
        if (byVer != null && byVer != p) return byVer;
        Pom byKey = byGa.get(p.parentCoordinate.ga());
        if (byKey != null && byKey != p) return byKey;
        return null;
    }

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
                    if (visiting.contains(gav)) continue;
                    Pom bom = byGav.get(gav);
                    if (bom == null) bom = byGa.get((g == null ? "" : g) + ":" + (a == null ? "" : a));
                    if (bom == null) continue;
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
        result.putAll(explicit);
        return result;
    }

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
            boolean suspect = unresolved && !external;
            if (unresolved && resolved != null) {
                origin = external ? "unresolved(external?)" : "unresolved(tree-complete!)";
            }
            out.add(new ResolvedDependency(groupId, artifactId, rawVersion, resolved, scope, origin, suspect));
        }
        return out;
    }

    private boolean dependsOnOutsideTree(Pom pom) {
        List<Pom> chain = chainRootFirst(pom);
        Pom root = chain.get(0);
        if (root.parentCoordinate != null && root.resolvedParent == null) {
            return true;
        }
        return hasMissingBomImport(pom, new HashSet<String>());
    }

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
                sb.append(value, start, end + 1);
            } else {
                sb.append(substitute(replacement, props, depth + 1));
            }
            i = end + 1;
        }
        return sb.toString();
    }

    private static String systemFallback(String key) {
        if (key.startsWith("env.")) {
            return System.getenv(key.substring(4));
        }
        return System.getProperty(key);
    }
}
