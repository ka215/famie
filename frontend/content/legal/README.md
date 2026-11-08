# 法務文書の公開用ソース

言語別Markdownとmanifest.jsonを公開用の正本とする。現在は0.1.0の3文書・日英6本文を先行確認用として登録。preview=true、publishedOn=nullで未公開・未施行を表示し、noindexを付ける。商用公開・本文承認の完了を意味しない。

`pnpm legal:generate`で検証・静的HTML生成。build/generate/devからも実行する。生成先public/legalはGit管理しない。公開済みの版は上書きせず新しい版を追加する。

manifestの各文書にはid（terms/privacy/cookies）、version、titles（ja/en）、publishedOn（YYYY-MM-DD）、effectiveAt、effectiveOnRelease、authoritativeLocale（ja）、current、requiresAcceptanceを指定する。施行暦日未確定時はeffectiveAt=null、effectiveOnRelease=1.0.0とする。公開日は実際の公開準備時に設定し、開発日から自動補完しない。

preview=trueの場合のみpublishedOn=nullを許可する。公開前にpreviewの扱い・実際の公開日を確定する。robots指定はアクセス制限ではなく、未公開資産の秘密保持を保証するものではない。公開版の不変性確認は配布済みマニフェストとの照合も必要。

本文は`<id>/<version>/ja.md`と`en.md`へUTF-8・BOMなし・LFで配置する。REVIEW注記、生HTML、画像、コードブロック、危険なURLは禁止。公開対象がある場合は3文書すべての現行版と日英が必要。
