## 2012-09-20

Doklady mají základní stavy dokumentů. Na ostrých datech je třeba pustit SQL příkaz

    update e10doc_core_heads set docState = 4000, docStateMain = 2 where docState = 0


## 2012-09-18

Došlo k přejmenování tabulek dokladů. SQL příkaz pro přejmenování je

    ALTER TABLE `e10_docs_heads` RENAME TO `e10doc_core_heads`, COMMENT='' REMOVE PARTITIONING;
    ALTER TABLE `e10_docs_rows` RENAME TO `e10doc_core_rows`, COMMENT='' REMOVE PARTITIONING;
    ALTER TABLE `e10_docs_taxes` RENAME TO `e10doc_core_taxes`, COMMENT='' REMOVE PARTITIONING;
