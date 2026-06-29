package com.example.pomparser;

import java.io.File;
import java.io.PrintStream;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/**
 * pom.xml 解析ツールのエントリポイント。
 *
 * 使い方:
 *   java -cp out com.example.pomparser.Main <フォルダ> [オプション]
 *
 * オプション:
 *   --csv         CSV 形式で出力（既定はテキスト）
 *   --props       各プロジェクトの実効プロパティも表示
 *   --include-target   target ディレクトリ配下も走査（既定は除外）
 *
 * 指定フォルダ以下を再帰的に走査し、見つかった pom.xml すべてについて
 * 親子関係・プロパティ・dependencyManagement を考慮して
 * ライブラリとバージョンを解決して出力する。
 *
 * JDK 1.8 標準ライブラリのみ使用。
 */
public final class Main {

    public static void main(String[] args) {
        // コンソールの既定文字コードに依存せず UTF-8 で出力する
        PrintStream out;
        try {
            out = new PrintStream(System.out, true, "UTF-8");
        } catch (Exception e) {
            out = System.out;
        }

        if (args.length < 1) {
            System.err.println("使い方: java -cp out com.example.pomparser.Main <フォルダ> [--csv] [--props] [--include-target]");
            System.exit(2);
            return;
        }

        File root = new File(args[0]);
        boolean csv = false;
        boolean showProps = false;
        boolean includeTarget = false;
        for (int i = 1; i < args.length; i++) {
            if ("--csv".equals(args[i])) csv = true;
            else if ("--props".equals(args[i])) showProps = true;
            else if ("--include-target".equals(args[i])) includeTarget = true;
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

        if (csv) {
            printCsv(out, poms, resolver);
        } else {
            printText(out, root, poms, resolver, showProps);
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
                    String ver = d.resolvedVersion != null ? d.resolvedVersion : "(未解決)";
                    StringBuilder line = new StringBuilder();
                    line.append("    - ").append(d.groupId).append(":").append(d.artifactId)
                        .append(" : ").append(ver);
                    if (d.scope != null) line.append(" [").append(d.scope).append("]");
                    line.append("  <").append(d.origin).append(">");
                    if (d.resolvedVersion != null && d.rawVersion != null
                            && !d.resolvedVersion.equals(d.rawVersion)) {
                        line.append("  (raw: ").append(d.rawVersion).append(")");
                    }
                    out.println(line.toString());
                }
            }
        }
    }

    // ---- CSV 出力 ----

    private static void printCsv(PrintStream out, List<Pom> poms, PomResolver resolver) {
        out.println("project,pomFile,depGroupId,depArtifactId,resolvedVersion,rawVersion,scope,versionOrigin");
        for (Pom pom : poms) {
            String project = pom.coordinate().gav();
            String path = pom.file.getAbsolutePath();
            for (ResolvedDependency d : resolver.resolveDependencies(pom)) {
                out.println(csv(project) + "," + csv(path) + ","
                        + csv(d.groupId) + "," + csv(d.artifactId) + ","
                        + csv(d.resolvedVersion) + "," + csv(d.rawVersion) + ","
                        + csv(d.scope) + "," + csv(d.origin));
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

    private Main() {
    }
}
