---
title: PDF test child switching the parent rule off
login:
  parent_acl: false
pdf:
  enabled: true
  cover: false
  toc: false
---

PDFTEST-OVERRIDE-BODY Der Seiten-Header muss die Plugin-Vorgabe überstimmen:
auch bei `parent_acl: true` im Plugin gilt für diese Seite die Regel des
Elternteils nicht.