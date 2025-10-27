import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';

const production = !process.env.ROLLUP_WATCH;

export default [
  // ES Module build (for modern bundlers)
  {
    input: 'assets/src/index.js',
    output: {
      file: 'assets/dist/logger.js',
      format: 'es',
      sourcemap: true
    },
    plugins: [
      resolve(),
      commonjs(),
      production && terser()
    ]
  },
  // UMD build (for script tag usage)
  {
    input: 'assets/src/index.js',
    output: {
      file: 'assets/dist/logger.umd.js',
      format: 'umd',
      name: 'ApplicationLogger',
      sourcemap: true
    },
    plugins: [
      resolve(),
      commonjs(),
      production && terser()
    ]
  }
];
