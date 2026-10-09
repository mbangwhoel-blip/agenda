Tempatkan binary OCR portable di folder ini (tanpa instalasi sistem):

- `tools/ocr/tesseract/tesseract.exe`
- `tools/ocr/poppler/pdftotext.exe`
- `tools/ocr/poppler/pdftoppm.exe`
- `tools/ocr/imagemagick/magick.exe` (opsional, fallback)

Catatan:
- Prioritas pembacaan AI: `pdftotext` -> `pdftoppm + tesseract` -> `magick + tesseract`.
- Jika tidak diletakkan di folder ini, Anda bisa set ENV:
  - `AGENDA_OCR_TESSERACT`
  - `AGENDA_OCR_PDFTOTEXT`
  - `AGENDA_OCR_PDFTOPPM`
  - `AGENDA_OCR_MAGICK`
