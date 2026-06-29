package com.example.pomparser;

import java.io.File;
import javax.xml.parsers.DocumentBuilder;
import javax.xml.parsers.DocumentBuilderFactory;
import org.w3c.dom.Document;
import org.w3c.dom.Element;
import org.w3c.dom.Node;
import org.w3c.dom.NodeList;

/**
 * 1 つの pom.xml ファイルを DOM で読み、生データを {@link Pom} に詰める。
 * JDK 標準の javax.xml.parsers のみを使用。
 */
final class PomReader {

    private final DocumentBuilderFactory factory;

    PomReader() {
        factory = DocumentBuilderFactory.newInstance();
        factory.setNamespaceAware(false); // pom は通常デフォルト名前空間なので prefix 無しで扱える
        factory.setIgnoringComments(true);
        // 外部実体参照を無効化（XXE 対策・JDK1.8 で利用可能な機能のみ）
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

        // properties
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

        // dependencies
        Element deps = firstChildElement(project, "dependencies");
        if (deps != null) {
            readDependencies(deps, pom.dependencies);
        }

        // dependencyManagement/dependencies
        Element depMgmt = firstChildElement(project, "dependencyManagement");
        if (depMgmt != null) {
            Element managed = firstChildElement(depMgmt, "dependencies");
            if (managed != null) {
                readDependencies(managed, pom.dependencyManagement);
            }
        }

        return pom;
    }

    private void readDependencies(Element dependenciesEl, java.util.List<Dependency> out) {
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

    // ---- DOM ヘルパ ----

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

    private static java.util.List<Element> childElements(Element parent, String tag) {
        java.util.List<Element> list = new java.util.ArrayList<Element>();
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
