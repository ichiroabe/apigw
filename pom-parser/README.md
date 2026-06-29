# pom.xml 解析ツール

指定フォルダ以下を再帰的に走査し、見つかった `pom.xml` すべてについて
**ライブラリ（groupId:artifactId）とバージョン**を一覧化するツールです。

- **親子関係を考慮**: `<parent>` を辿り、子で未定義の groupId / version を親から継承します。
- **プロパティを先読み**: 親チェーン全体の `<properties>` を集約し、`${...}` を展開します（子が親を上書き）。
- **親での定義を考慮**: `<dependencyManagement>`（親含む）からバージョンを解決します。
- **JDK 1.8 標準ライブラリのみ**: 外部依存なし（`javax.xml` の DOM パーサを使用）。

## ビルド

JDK 1.8 以上が必要です。

```sh
# Linux / macOS
./build.sh

# Windows
build.bat
```

`out/` にクラスファイルが生成されます。

> 注: JDK 1.8 でビルドする場合は `javac -d out src/com/example/pomparser/*.java` だけで構いません。
> ビルドスクリプトでは新しい JDK でも 1.8 互換になるよう `-source 8 -target 8` を付けています。

## 実行

```sh
java -cp out com.example.pomparser.Main <フォルダ> [オプション]
```

### オプション

| オプション           | 説明                                            |
|----------------------|-------------------------------------------------|
| `--csv`              | CSV 形式で出力（既定はテキスト）                 |
| `--props`            | 各プロジェクトの実効プロパティも表示             |
| `--include-target`   | `target` ディレクトリ配下も走査（既定は除外）    |

`.git` ディレクトリと（既定で）`target` ディレクトリは走査対象から除外します。

## 出力例（テキスト）

```
■ プロジェクト: com.demo:module-a:1.0.0
  ファイル   : .../sample/parent/moduleA/pom.xml
  親         : com.demo:demo-parent:1.0.0 （ツリー内で解決済み）
  依存 (2):
    - com.google.guava:guava : 32.1.3-jre  <dependencyManagement>  (raw: ${guava.version})
    - junit:junit : 4.13.2 [test]  <dependencyManagement>  (raw: ${junit.version})
```

- `<...>` はバージョンの**出所**を示します（`dependency` / `dependencyManagement` / `unresolved`）。
- `raw:` は `pom.xml` に書かれていた展開前の値です。
- バージョンを解決できなかった場合は `(未解決)` と表示します。

## 出力例（CSV）

```
project,pomFile,depGroupId,depArtifactId,resolvedVersion,rawVersion,scope,versionOrigin
com.demo:module-a:1.0.0,.../moduleA/pom.xml,com.google.guava,guava,32.1.3-jre,${guava.version},,dependencyManagement
```

## バージョン解決の優先順位

1. `dependency` に直接書かれた `<version>`（`${...}` 展開）
2. 自身〜親チェーンの `dependencyManagement` の `<version>`（`${...}` 展開）

プロパティは「親を先に適用し子で上書き」した実効表を使用し、
`project.version` などの組み込みプロパティと、`${java.version}` のような
システムプロパティ／`${env.XXX}` 環境変数のフォールバックにも対応します。

## 制限事項

- BOM（`<scope>import</scope>` の `dependencyManagement` インポート）は未対応です。
- リモートリポジトリからの親 POM 取得は行いません。親はスキャン対象フォルダ内で
  解決できた場合のみ継承します（ツリー外の親は座標のみ表示します）。
- プロファイルによる依存の追加・上書きは考慮しません。

## ディレクトリ構成

```
pom-parser/
├── README.md
├── build.sh / build.bat
└── src/com/example/pomparser/
    ├── Main.java               エントリポイント（走査・出力）
    ├── PomReader.java          pom.xml を DOM で読み生データ化
    ├── PomResolver.java        親子・プロパティ・depMgmt 解決
    ├── Pom.java                pom 1 件のモデル
    ├── Coordinate.java         groupId:artifactId:version
    ├── Dependency.java         dependency / depMgmt の 1 エントリ
    └── ResolvedDependency.java 解決後の依存
```
