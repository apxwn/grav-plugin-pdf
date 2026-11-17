---
title: Ein Artikel
pdf:
  enabled: true
  images: true
---
Ein einzelner Artikel, klein genug, um in Millisekunden zu rendern. Die
Cache-Tests benutzen ihn, weil sie an der Dauer eines Buches nicht interessiert
sind.

<p id="fixture-anchor" onclick="bad()" style="color:red">Fixture content with <a href="https://safe.example.test/content">a safe link</a>, <a href="#fixture-anchor">a fragment link</a> and a <img src="/user/pages/02.article/pixel.png" alt="pixel" onerror="bad()">.</p>
<img src="https://evil.invalid/remote.png" alt="remote">
<a href="javascript:alert(1)">unsafe scheme</a>
<svg><image href="https://evil.invalid/vector.png"></image></svg>
<iframe src="https://evil.invalid/frame">embedded content</iframe>
