module.exports = {
  darkMode: 'class',
  content: [
    "./*.php",
    "./includes/**/*.php",
    "./app/**/*.php",
    "./assets/js/**/*.js"
  ],
  theme: {
    extend: {
      colors: {
        brand: { // Kemenkes Teal (C 75, M 0, Y 45, K 0)
          50: '#e6f6f3',
          100: '#c2ebe2',
          200: '#94dbcc',
          300: '#5cc7b2',
          400: '#2db097',
          500: '#00b294',
          600: '#00907a',
          700: '#007464',
          800: '#005d52',
          900: '#004c45',
        },
        kcyan: { // Kemenkes Cyan (C 74, M 0, Y 21, K 0)
          50: '#e6f8fa',
          100: '#c1edf3',
          200: '#8edbea',
          300: '#52c5db',
          400: '#21abc7',
          500: '#00bace',
          600: '#0097a8',
          700: '#007987',
          800: '#00626d',
          900: '#00515c',
        },
        klime: { // Kemenkes Lime (C 20, M 0, Y 100, K 0)
          50: '#fbfdf0',
          100: '#f5fbdb',
          200: '#ecf6b7',
          300: '#e2ef8d',
          400: '#d7e75e',
          500: '#d8df21',
          600: '#acc100',
          700: '#839500',
          800: '#677500',
          900: '#576304',
        },
        kgray: { // Kemenkes Gray (C 46, M 38, Y 39, K 3)
          50: '#f5f6f6',
          100: '#e6e8e8',
          200: '#ced3d3',
          300: '#aab2b2',
          400: '#8d9293',
          500: '#6b7273',
          600: '#525859',
          700: '#434849',
          800: '#383c3c',
          900: '#313435',
        }
      },
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui']
      }
    }
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}
