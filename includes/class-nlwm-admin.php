<?php
/**
 * Admin screen and AJAX endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backups can be many gigabytes: they are streamed in small resumable chunks with fopen/fread/fwrite and finished with an atomic rename. WP_Filesystem has no streaming API and may need FTP credentials, which a background job cannot ask for. Only files inside the plugin's own backup folder are touched.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename

class NLWM_Admin {

	/** Menu icon: the website logo (∞ with the migration arrow) as one filled path, traced from logo-mark.svg. */
	const ICON_PATH = 'M9.18 10.07L9.03 9.87L10.71 8.65L10.86 8.85ZM9.07 9.92L8.92 9.72L10.59 8.49L10.74 8.69ZM8.96 9.78L8.81 9.57L10.47 8.32L10.62 8.53ZM8.85 9.63L8.69 9.43L10.35 8.16L10.5 8.36ZM8.74 9.48L8.58 9.28L10.22 8L10.38 8.2ZM8.62 9.34L8.47 9.14L10.09 7.84L10.25 8.04ZM8.51 9.19L8.35 9L9.96 7.68L10.12 7.88ZM8.4 9.05L8.23 8.86L9.83 7.53L9.99 7.72ZM8.28 8.91L8.12 8.72L9.7 7.37L9.86 7.56ZM8.16 8.78L8 8.59L9.56 7.21L9.73 7.4ZM8.05 8.64L7.88 8.45L9.42 7.06L9.59 7.25ZM7.93 8.51L7.75 8.32L9.28 6.91L9.45 7.09ZM7.81 8.38L7.63 8.2L9.13 6.75L9.31 6.94ZM7.69 8.25L7.51 8.07L8.98 6.6L9.16 6.78ZM7.56 8.13L7.38 7.95L8.82 6.46L9.01 6.63ZM7.44 8.01L7.26 7.84L8.67 6.31L8.85 6.48ZM7.32 7.89L7.13 7.73L8.5 6.17L8.69 6.34ZM7.19 7.78L7 7.62L8.34 6.03L8.53 6.19ZM7.07 7.67L6.87 7.52L8.16 5.89L8.36 6.05ZM6.94 7.57L6.73 7.42L7.98 5.76L8.19 5.91ZM6.81 7.47L6.6 7.33L7.8 5.63L8.01 5.77ZM6.68 7.38L6.47 7.24L7.61 5.5L7.82 5.64ZM6.55 7.29L6.33 7.16L7.41 5.38L7.62 5.51ZM6.42 7.21L6.2 7.09L7.2 5.27L7.42 5.39ZM6.29 7.14L6.06 7.03L6.99 5.16L7.22 5.28ZM6.16 7.07L5.93 6.97L6.77 5.07L7 5.17ZM6.03 7.01L5.79 6.92L6.54 4.98L6.78 5.07ZM5.9 6.96L5.66 6.88L6.3 4.9L6.55 4.98ZM5.77 6.91L5.53 6.85L6.06 4.84L6.31 4.9ZM5.64 6.88L5.39 6.82L5.82 4.79L6.06 4.84ZM5.51 6.85L5.26 6.81L5.57 4.75L5.82 4.79ZM5.38 6.82L5.13 6.8L5.31 4.73L5.57 4.75ZM5.25 6.81L4.99 6.8L5.06 4.72L5.31 4.73ZM5.11 6.8L4.85 6.8L4.81 4.72L5.06 4.72ZM4.97 6.8L4.71 6.82L4.57 4.74L4.82 4.72ZM4.82 6.81L4.57 6.84L4.32 4.77L4.57 4.74ZM4.68 6.82L4.43 6.86L4.08 4.81L4.33 4.77ZM4.54 6.84L4.3 6.9L3.84 4.87L4.09 4.81ZM4.41 6.87L4.16 6.94L3.6 4.93L3.85 4.87ZM4.27 6.9L4.03 6.99L3.37 5.01L3.61 4.93ZM4.13 6.95L3.9 7.04L3.14 5.1L3.38 5.01ZM4 7L3.77 7.1L2.91 5.2L3.15 5.1ZM3.87 7.05L3.65 7.17L2.69 5.32L2.92 5.2ZM3.75 7.11L3.53 7.24L2.48 5.44L2.7 5.31ZM3.63 7.18L3.41 7.32L2.27 5.58L2.49 5.44ZM3.51 7.25L3.3 7.4L2.07 5.72L2.28 5.57ZM3.39 7.33L3.19 7.49L1.88 5.88L2.08 5.72ZM3.28 7.42L3.09 7.59L1.7 6.05L1.89 5.88ZM3.18 7.51L3 7.69L1.52 6.22L1.7 6.04ZM3.07 7.61L2.9 7.8L1.36 6.41L1.53 6.22ZM2.98 7.71L2.82 7.91L1.2 6.6L1.36 6.4ZM2.89 7.82L2.74 8.02L1.06 6.8L1.21 6.59ZM2.81 7.93L2.67 8.14L0.92 7.01L1.06 6.8ZM2.73 8.04L2.6 8.26L0.8 7.22L0.92 7ZM2.66 8.16L2.54 8.39L0.69 7.45L0.8 7.22ZM2.59 8.28L2.49 8.51L0.59 7.67L0.69 7.44ZM2.53 8.41L2.44 8.64L0.5 7.91L0.59 7.67ZM2.48 8.53L2.4 8.78L0.42 8.15L0.5 7.9ZM2.44 8.66L2.37 8.91L0.36 8.39L0.42 8.14ZM2.4 8.8L2.35 9.05L0.31 8.63L0.36 8.38ZM2.37 8.93L2.33 9.18L0.27 8.88L0.31 8.63ZM2.34 9.07L2.32 9.32L0.25 9.13L0.27 8.87ZM2.33 9.21L2.32 9.46L0.24 9.38L0.25 9.12ZM2.32 9.35L2.32 9.6L0.24 9.62L0.24 9.37ZM2.32 9.48L2.33 9.74L0.26 9.87L0.24 9.62ZM2.32 9.62L2.35 9.87L0.29 10.12L0.26 9.87ZM2.34 9.76L2.38 10.01L0.33 10.36L0.29 10.11ZM2.36 9.9L2.41 10.14L0.39 10.61L0.33 10.36ZM2.39 10.03L2.46 10.28L0.46 10.85L0.39 10.6ZM2.42 10.16L2.5 10.4L0.54 11.08L0.45 10.84ZM2.46 10.3L2.56 10.53L0.63 11.31L0.54 11.08ZM2.51 10.43L2.62 10.65L0.74 11.54L0.63 11.31ZM2.57 10.55L2.69 10.77L0.86 11.76L0.74 11.54ZM2.63 10.67L2.76 10.89L0.99 11.97L0.85 11.76ZM2.7 10.79L2.84 11L1.13 12.18L0.98 11.97ZM2.78 10.91L2.93 11.11L1.28 12.38L1.12 12.17ZM2.86 11.02L3.02 11.22L1.44 12.56L1.27 12.37ZM2.95 11.13L3.12 11.32L1.61 12.74L1.43 12.56ZM3.04 11.24L3.22 11.41L1.79 12.92L1.6 12.74ZM3.14 11.33L3.33 11.5L1.97 13.08L1.78 12.91ZM3.24 11.43L3.44 11.58L2.17 13.23L1.97 13.07ZM3.35 11.52L3.56 11.66L2.37 13.37L2.17 13.22ZM3.47 11.6L3.68 11.73L2.58 13.5L2.37 13.37ZM3.58 11.67L3.81 11.8L2.8 13.62L2.58 13.5ZM3.7 11.74L3.93 11.86L3.02 13.73L2.79 13.62ZM3.83 11.81L4.06 11.91L3.25 13.82L3.02 13.72ZM3.96 11.87L4.2 11.95L3.48 13.91L3.24 13.82ZM4.09 11.92L4.33 11.99L3.72 13.98L3.48 13.91ZM4.22 11.96L4.47 12.02L3.96 14.04L3.71 13.98ZM4.36 12L4.61 12.05L4.2 14.09L3.95 14.04ZM4.5 12.03L4.75 12.07L4.44 14.12L4.19 14.09ZM4.64 12.05L4.89 12.08L4.69 14.15L4.43 14.12ZM4.78 12.07L5.03 12.08L4.93 14.16L4.68 14.15ZM4.92 12.08L5.17 12.08L5.18 14.16L4.92 14.16ZM5.05 12.08L5.31 12.07L5.43 14.14L5.18 14.16ZM5.19 12.08L5.44 12.05L5.68 14.11L5.43 14.14ZM5.32 12.07L5.57 12.02L5.93 14.07L5.68 14.11ZM5.45 12.05L5.7 11.99L6.18 14.01L5.93 14.07ZM5.59 12.02L5.83 11.95L6.42 13.94L6.17 14.01ZM5.72 11.98L5.96 11.9L6.65 13.86L6.41 13.94ZM5.86 11.94L6.09 11.84L6.88 13.77L6.64 13.86ZM5.99 11.88L6.22 11.78L7.1 13.66L6.87 13.77ZM6.13 11.82L6.35 11.71L7.31 13.55L7.09 13.67ZM6.26 11.76L6.48 11.63L7.52 13.43L7.3 13.56ZM6.4 11.68L6.61 11.55L7.71 13.31L7.5 13.44ZM6.53 11.6L6.74 11.46L7.9 13.18L7.69 13.32ZM6.66 11.51L6.87 11.36L8.09 13.05L7.88 13.2ZM6.8 11.42L7 11.26L8.27 12.91L8.07 13.06ZM6.93 11.32L7.12 11.16L8.44 12.77L8.24 12.93ZM7.06 11.21L7.25 11.05L8.61 12.62L8.41 12.79ZM7.19 11.1L7.37 10.93L8.77 12.48L8.58 12.65ZM7.31 10.99L7.5 10.81L8.92 12.33L8.74 12.5ZM7.44 10.87L7.62 10.69L9.08 12.18L8.9 12.36ZM7.56 10.75L7.74 10.57L9.23 12.03L9.05 12.21ZM7.69 10.62L7.86 10.44L9.37 11.87L9.2 12.06ZM7.81 10.5L7.98 10.31L9.51 11.72L9.34 11.9ZM7.93 10.37L8.1 10.18L9.65 11.56L9.48 11.75ZM8.05 10.23L8.22 10.04L9.79 11.4L9.62 11.6ZM8.17 10.1L8.33 9.9L9.92 11.25L9.76 11.44ZM8.29 9.96L8.45 9.76L10.05 11.09L9.89 11.28ZM8.4 9.82L8.56 9.62L10.18 10.93L10.02 11.12ZM8.52 9.68L8.68 9.48L10.31 10.76L10.15 10.96ZM8.63 9.53L8.79 9.33L10.43 10.6L10.28 10.8ZM8.74 9.39L8.9 9.18L10.56 10.44L10.4 10.64ZM8.86 9.24L9.01 9.04L10.68 10.28L10.53 10.48ZM8.97 9.09L9.12 8.89L10.8 10.11L10.65 10.32ZM9.08 8.94L9.23 8.73L10.91 9.95L10.76 10.16ZM9.19 8.78L9.34 8.57L11.02 9.8L10.87 10.01ZM9.31 8.62L9.46 8.41L11.13 9.65L10.98 9.86ZM9.43 8.45L9.59 8.25L11.25 9.51L11.09 9.71ZM9.56 8.29L9.71 8.09L11.36 9.36L11.2 9.56ZM9.68 8.13L9.84 7.93L11.47 9.22L11.32 9.41ZM9.81 7.97L9.97 7.77L11.59 9.07L11.43 9.27ZM9.94 7.81L10.1 7.61L11.7 8.93L11.54 9.13ZM10.07 7.65L10.23 7.45L11.82 8.79L11.66 8.99ZM10.2 7.49L10.37 7.3L11.94 8.66L11.77 8.85ZM10.34 7.33L10.5 7.14L12.06 8.53L11.89 8.71ZM10.47 7.18L10.65 6.99L12.18 8.39L12.01 8.58ZM10.62 7.02L10.79 6.84L12.3 8.27L12.13 8.45ZM10.76 6.87L10.94 6.69L12.42 8.14L12.25 8.32ZM10.91 6.71L11.09 6.54L12.55 8.02L12.37 8.2ZM11.06 6.56L11.25 6.39L12.68 7.9L12.49 8.08ZM11.22 6.41L11.41 6.24L12.8 7.79L12.62 7.96ZM11.38 6.27L11.57 6.1L12.93 7.68L12.74 7.84ZM11.55 6.12L11.74 5.96L13.06 7.57L12.87 7.73ZM11.72 5.98L11.92 5.83L13.19 7.47L12.99 7.63ZM11.9 5.84L12.1 5.7L13.33 7.38L13.12 7.53ZM12.08 5.71L12.29 5.57L13.46 7.29L13.25 7.43ZM12.27 5.58L12.48 5.45L13.59 7.21L13.38 7.34ZM12.47 5.46L12.69 5.33L13.73 7.13L13.51 7.26ZM12.67 5.34L12.9 5.22L13.86 7.06L13.64 7.18ZM12.88 5.23L13.11 5.12L14 7L13.77 7.11ZM13.1 5.12L13.34 5.03L14.13 6.95L13.9 7.04ZM13.33 5.03L13.57 4.94L14.27 6.9L14.03 6.99ZM13.56 4.95L13.81 4.87L14.4 6.87L14.16 6.94ZM13.8 4.87L14.05 4.81L14.53 6.84L14.29 6.9ZM14.05 4.81L14.3 4.77L14.67 6.82L14.42 6.86ZM14.3 4.77L14.55 4.74L14.8 6.8L14.55 6.83ZM14.55 4.74L14.8 4.72L14.94 6.8L14.68 6.81ZM14.8 4.72L15.05 4.72L15.07 6.8L14.82 6.8ZM15.05 4.72L15.3 4.73L15.21 6.81L14.96 6.8ZM15.29 4.73L15.55 4.75L15.35 6.83L15.1 6.8ZM15.54 4.75L15.79 4.79L15.49 6.85L15.24 6.81ZM15.78 4.79L16.03 4.84L15.63 6.88L15.38 6.83ZM16.02 4.84L16.27 4.9L15.77 6.91L15.52 6.85ZM16.26 4.89L16.51 4.97L15.9 6.96L15.66 6.88ZM16.5 4.97L16.74 5.05L16.03 7.01L15.79 6.92ZM16.73 5.05L16.96 5.15L16.16 7.07L15.93 6.97ZM16.96 5.15L17.19 5.26L16.29 7.13L16.06 7.02ZM17.18 5.25L17.4 5.37L16.41 7.2L16.18 7.08ZM17.4 5.37L17.61 5.5L16.53 7.28L16.31 7.14ZM17.61 5.5L17.82 5.64L16.64 7.36L16.43 7.21ZM17.81 5.64L18.01 5.79L16.75 7.44L16.55 7.29ZM18.01 5.79L18.2 5.95L16.85 7.54L16.66 7.37ZM18.2 5.95L18.38 6.13L16.95 7.64L16.77 7.46ZM18.38 6.12L18.55 6.3L17.05 7.74L16.87 7.56ZM18.55 6.3L18.71 6.49L17.13 7.85L16.97 7.65ZM18.71 6.49L18.87 6.69L17.22 7.96L17.06 7.76ZM18.86 6.69L19.01 6.89L17.29 8.08L17.15 7.87ZM19 6.89L19.14 7.11L17.36 8.2L17.23 7.98ZM19.13 7.1L19.26 7.33L17.43 8.32L17.31 8.1ZM19.25 7.32L19.36 7.55L17.48 8.44L17.37 8.22ZM19.36 7.55L19.46 7.78L17.53 8.57L17.44 8.34ZM19.46 7.78L19.54 8.02L17.58 8.7L17.49 8.46ZM19.54 8.01L19.61 8.26L17.61 8.84L17.54 8.59ZM19.61 8.25L19.67 8.5L17.64 8.97L17.58 8.73ZM19.66 8.5L19.71 8.75L17.66 9.11L17.62 8.86ZM19.71 8.74L19.74 8.99L17.67 9.25L17.64 8.99ZM19.74 8.99L19.76 9.24L17.68 9.38L17.66 9.13ZM19.76 9.24L19.76 9.49L17.68 9.52L17.68 9.27ZM19.76 9.48L19.75 9.74L17.67 9.66L17.68 9.41ZM19.75 9.73L19.73 9.98L17.66 9.8L17.68 9.55ZM19.73 9.98L19.69 10.23L17.63 9.94L17.67 9.69ZM19.69 10.23L19.64 10.48L17.6 10.07L17.65 9.82ZM19.65 10.47L19.58 10.72L17.57 10.2L17.63 9.96ZM19.58 10.71L19.51 10.96L17.52 10.33L17.6 10.09ZM19.51 10.95L19.42 11.19L17.47 10.46L17.56 10.23ZM19.42 11.19L19.32 11.42L17.41 10.59L17.52 10.36ZM19.32 11.41L19.21 11.64L17.35 10.71L17.46 10.48ZM19.21 11.64L19.09 11.86L17.28 10.83L17.4 10.61ZM19.09 11.85L18.95 12.07L17.2 10.94L17.34 10.73ZM18.95 12.06L18.81 12.27L17.12 11.05L17.27 10.85ZM18.81 12.26L18.65 12.46L17.03 11.16L17.19 10.96ZM18.65 12.46L18.49 12.65L16.93 11.26L17.1 11.07ZM18.49 12.64L18.31 12.82L16.83 11.36L17.01 11.18ZM18.32 12.82L18.13 12.99L16.73 11.45L16.92 11.28ZM18.13 12.99L17.94 13.15L16.62 11.54L16.81 11.38ZM17.94 13.14L17.74 13.29L16.5 11.62L16.71 11.47ZM17.74 13.29L17.53 13.43L16.38 11.69L16.6 11.55ZM17.54 13.43L17.32 13.56L16.26 11.76L16.48 11.63ZM17.32 13.55L17.1 13.67L16.14 11.82L16.36 11.71ZM17.1 13.67L16.87 13.77L16.01 11.88L16.24 11.78ZM16.88 13.77L16.64 13.86L15.88 11.93L16.11 11.84ZM16.65 13.86L16.41 13.94L15.74 11.97L15.98 11.89ZM16.42 13.94L16.17 14.01L15.61 12.01L15.85 11.94ZM16.18 14.01L15.93 14.06L15.47 12.04L15.72 11.98ZM15.94 14.06L15.69 14.11L15.33 12.06L15.58 12.01ZM15.7 14.11L15.45 14.14L15.19 12.07L15.44 12.04ZM15.45 14.14L15.2 14.15L15.05 12.08L15.3 12.06ZM15.21 14.15L14.96 14.16L14.9 12.08L15.16 12.07ZM14.54 10.29L16.01 12.58L14.26 13.71L12.79 11.42ZM15.65 14.02L13.28 15.33L12.27 13.5L14.64 12.19ZM11.04 9.44L10.99 9.76L10.84 10.05L10.61 10.28L10.32 10.43L10 10.48L9.68 10.43L9.39 10.28L9.16 10.05L9.01 9.76L8.96 9.44L9.01 9.12L9.16 8.83L9.39 8.6L9.68 8.45L10 8.4L10.32 8.45L10.61 8.6L10.84 8.83L10.99 9.12ZM16 13.12L15.95 13.44L15.8 13.73L15.57 13.96L15.28 14.11L14.96 14.16L14.64 14.11L14.35 13.96L14.12 13.73L13.97 13.44L13.92 13.12L13.97 12.8L14.12 12.51L14.35 12.28L14.64 12.13L14.96 12.08L15.28 12.13L15.57 12.28L15.8 12.51L15.95 12.8ZM14.72 10.88L14.67 11.2L14.52 11.49L14.29 11.72L14 11.87L13.68 11.92L13.36 11.87L13.07 11.72L12.84 11.49L12.69 11.2L12.64 10.88L12.69 10.56L12.84 10.27L13.07 10.04L13.36 9.89L13.68 9.84L14 9.89L14.29 10.04L14.52 10.27L14.67 10.56ZM16.16 13.12L16.11 13.44L15.96 13.73L15.73 13.96L15.44 14.11L15.12 14.16L14.8 14.11L14.51 13.96L14.28 13.73L14.13 13.44L14.08 13.12L14.13 12.8L14.28 12.51L14.51 12.28L14.8 12.13L15.12 12.08L15.44 12.13L15.73 12.28L15.96 12.51L16.11 12.8ZM13.84 14.4L13.79 14.72L13.64 15.01L13.41 15.24L13.12 15.39L12.8 15.44L12.48 15.39L12.19 15.24L11.96 15.01L11.81 14.72L11.76 14.4L11.81 14.08L11.96 13.79L12.19 13.56L12.48 13.41L12.8 13.36L13.12 13.41L13.41 13.56L13.64 13.79L13.79 14.08Z';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . NLWM_BASENAME, array( __CLASS__, 'action_links' ) );
		add_action( 'init', array( __CLASS__, 'maintenance_gate' ), 0 );

		$private = array( 'start', 'inspect', 'upload', 'backups', 'delete', 'label', 'lock', 'link', 'settings', 'log', 'storage_save', 'remote_files', 'remote_delete' );
		foreach ( $private as $a ) {
			add_action( 'wp_ajax_nlwm_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		// Steps authenticate with the job token: after a restore swaps the database, the current
		// login cookie may no longer be valid, but the job must still be able to finish.
		foreach ( array( 'step', 'cancel' ) as $a ) {
			add_action( 'wp_ajax_nlwm_' . $a, array( __CLASS__, 'ajax_' . $a ) );
			add_action( 'wp_ajax_nopriv_nlwm_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function menu() {
		// The ∞ logo as a filled shape (not a stroke): WordPress recolours only fills, so the icon follows the admin
		// colour scheme (grey, white on hover/active) instead of staying black on the dark menu.
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="' . self::ICON_PATH . '"/></svg>' );
		$brand = NLWM_Util::brand();
		if ( ! empty( $brand['white_label'] ) && ! empty( $brand['icon'] ) ) {
			$icon = $brand['icon']; // white-label (NoLimit Pro, Agency): the agency's logo as the menu icon
		}
		add_menu_page( $brand['name'], $brand['menu'], 'export', NLWM_SLUG, array( __CLASS__, 'page' ), $icon, 76 );
		foreach ( self::tabs() as $tab => $title ) {
			add_submenu_page( NLWM_SLUG, $title, $title, 'export', 'export' === $tab ? NLWM_SLUG : NLWM_SLUG . '&tab=' . $tab, array( __CLASS__, 'page' ) );
		}
	}

	/** Admin tabs. NoLimit Pro adds its own through the `nlwm_admin_tabs` filter. */
	public static function tabs() {
		return apply_filters( 'nlwm_admin_tabs', array(
			'export'   => 'Backup / Export',
			'import'   => 'Restore / Import',
			'backups'  => 'Backups',
			'schedule' => 'Schedule',
			'drive'    => 'Cloud storage',
		) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . NLWM_SLUG ) ) . '">Backup &amp; Migrate</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, NLWM_SLUG ) ) {
			return;
		}
		// File times in the version string, so browsers never keep an old copy after an update.
		$v = function ( $f ) {
			return NLWM_VERSION . '.' . (int) @filemtime( NLWM_DIR . '/assets/' . $f );
		};
		wp_enqueue_style( 'nlwm-admin', NLWM_URL . 'assets/admin.css', array(), $v( 'admin.css' ) );
		wp_enqueue_script( 'nlwm-admin', NLWM_URL . 'assets/admin.js', array(), $v( 'admin.js' ), true );
		wp_localize_script( 'nlwm-admin', 'NLWM', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'nlwm' ),
			'chunk'     => NLWM_Util::max_chunk_bytes(),
			'canImport' => current_user_can( 'import' ) && current_user_can( 'manage_options' ),
			'home'      => home_url(),
			'running'   => self::running_for_ui(),
			'storages'  => self::storages_for_ui(),
			'pro'       => (bool) apply_filters( 'nlwm_pro_active', false ),
			'storage'   => NLWM_Util::settings()['storage'],
		) );
	}

	/** Lets the page re-attach to a job that is still running (e.g. after a reload). */
	protected static function running_for_ui() {
		$job = NLWM_Job::running();
		if ( ! $job || ! current_user_can( 'export' ) ) {
			return null;
		}
		return array( 'id' => $job['id'], 'token' => $job['token'], 'type' => $job['type'] );
	}

	public static function page() {
		if ( ! current_user_can( 'export' ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( is_multisite() ) {
			echo '<div class="wrap"><h1>' . esc_html( NLWM_Util::brand()['name'] ) . '</h1><p>Multisite networks are not supported yet. The plugin works on single WordPress sites.</p></div>';
			return;
		}
		try {
			NLWM_Util::ensure_dirs();
			$nlwm_dir_error = '';
		} catch ( Exception $e ) {
			$nlwm_dir_error = $e->getMessage();
		}
		// Only picks which tab to show; nothing is changed, so no nonce is needed.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $tab, self::tabs() ) ) {
			$tab = 'export';
		}
		include NLWM_DIR . '/views/page.php';
	}

	/* ================================================================ helpers */

	protected static function guard( $cap = 'export' ) {
		if ( is_multisite() ) {
			wp_send_json_error( array( 'message' => 'Multisite networks are not supported yet.' ), 403 );
		}
		if ( ! check_ajax_referer( 'nlwm', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Your session expired. Reload the page.' ), 403 );
		}
		$caps = 'import' === $cap ? array( 'import', 'manage_options' ) : array( $cap );
		foreach ( $caps as $c ) {
			if ( ! current_user_can( $c ) ) {
				wp_send_json_error( array( 'message' => 'You are not allowed to do this.' ), 403 );
			}
		}
	}

	protected static function post( $key, $default = '' ) {
		// Only called after guard() checked the nonce and capability; every caller sanitizes the value it reads.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	protected static function fail( Exception $e ) {
		wp_send_json_error( array( 'message' => $e->getMessage() ) );
	}

	/* ================================================================ jobs */

	public static function ajax_start() {
		$type = sanitize_key( self::post( 'type' ) );
		self::guard( 'export' === $type ? 'export' : 'import' );
		try {
			$raw  = json_decode( (string) self::post( 'opts', '{}' ), true );
			$opts = self::clean_opts( $type, is_array( $raw ) ? $raw : array() );
			$job  = NLWM_Job::create( $type, $opts );
			$job  = NLWM_Job::run( $job, 5 );
			wp_send_json_success( array_merge( NLWM_Job::public_view( $job ), array( 'token' => $job['token'] ) ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	protected static function clean_opts( $type, array $raw ) {
		$out = array();
		if ( 'export' === $type ) {
			foreach ( NLWM_Util::skip_keys() as $k ) {
				if ( ! empty( $raw[ $k ] ) ) {
					$out[ $k ] = 1;
				}
			}
			$out['exclude_paths']  = isset( $raw['exclude_paths'] ) ? sanitize_textarea_field( $raw['exclude_paths'] ) : '';
			$out['max_file_mb']    = isset( $raw['max_file_mb'] ) ? absint( $raw['max_file_mb'] ) : 0;
			$out['exclude_tables'] = isset( $raw['exclude_tables'] ) ? array_map( 'sanitize_text_field', (array) $raw['exclude_tables'] ) : array();
			$out['label']          = isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : '';
			$out['origin']         = ( isset( $raw['origin'] ) && 'pre-import' === $raw['origin'] ) ? 'pre-import' : 'manual';
			$out['storages']       = 'manual' === $out['origin'] && ! empty( $raw['storages'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $raw['storages'] ), array_keys( NLWM_Storages::connected() ) ) ) : array();
			$out['encrypt']        = isset( $raw['password'] ) && '' !== (string) $raw['password'] && NLWM_Archive::can_seal();
			$out['password']       = $out['encrypt'] ? (string) $raw['password'] : '';
			$out['replace']        = array();
			if ( ! empty( $raw['replace'] ) && is_array( $raw['replace'] ) ) {
				foreach ( $raw['replace'] as $pair ) {
					if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '' !== trim( $pair[0] ) ) {
						$out['replace'][] = array( (string) $pair[0], (string) $pair[1] );
					}
				}
			}
		} elseif ( 'import' === $type ) {
			$name = isset( $raw['backup'] ) ? (string) $raw['backup'] : '';
			if ( ! NLWM_Util::valid_backup_name( $name ) || ! file_exists( NLWM_Util::backup_path( $name ) ) ) {
				throw new NLWM_Exception( 'Choose a backup to restore.' );
			}
			$out['backup']        = $name;
			$out['email_replace'] = ! empty( $raw['email_replace'] );
			$out['skip_files']    = ! empty( $raw['skip_files'] );
			$out['skip_db']       = ! empty( $raw['skip_db'] );
			$out['delete_after']  = ! empty( $raw['delete_after'] );
			$out['password']      = isset( $raw['password'] ) ? (string) $raw['password'] : '';
		} elseif ( 'fetch' === $type && ! empty( $raw['storage'] ) ) {
			$storage = NLWM_Storages::get( sanitize_key( $raw['storage'] ) );
			if ( ! $storage->connected() ) {
				throw new NLWM_Exception( esc_html( $storage->label() . ' is not connected.' ) );
			}
			$out['storage']   = $storage->id();
			$out['remote_id'] = preg_replace( '#[^A-Za-z0-9_./=+-]#', '', (string) $raw['remote_id'] );
		} elseif ( 'fetch' === $type ) {
			$url = isset( $raw['url'] ) ? esc_url_raw( trim( $raw['url'] ), array( 'http', 'https' ) ) : '';
			if ( ! $url ) {
				throw new NLWM_Exception( 'Enter a valid http(s) link to a backup file.' );
			}
			$out['url'] = $url;
		} else {
			throw new NLWM_Exception( 'Unknown action.' );
		}
		return $out;
	}

	public static function ajax_step() {
		try {
			$job = NLWM_Job::load_verified( sanitize_key( self::post( 'job' ) ), (string) self::post( 'token' ) );
			$job = NLWM_Job::run( $job );
			wp_send_json_success( NLWM_Job::public_view( $job ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_cancel() {
		try {
			$job = NLWM_Job::load_verified( sanitize_key( self::post( 'job' ) ), (string) self::post( 'token' ) );
			$job = NLWM_Job::cancel( $job );
			wp_send_json_success( NLWM_Job::public_view( $job ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_log() {
		self::guard();
		try {
			$id   = sanitize_key( self::post( 'job' ) );
			$file = NLWM_Job::path( $id, 'log' );
			wp_send_json_success( array( 'log' => file_exists( $file ) ? file_get_contents( $file ) : '' ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	/* ================================================================ upload */

	/**
	 * Chunked upload straight into the backups folder. Each chunk is smaller than the server's
	 * upload_max_filesize/post_max_size, so there is no size limit for the whole file.
	 */
	public static function ajax_upload() {
		self::guard( 'import' );
		try {
			NLWM_Util::ensure_dirs();
			$upload_id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) self::post( 'upload_id' ) ) );
			$original  = sanitize_file_name( (string) self::post( 'name' ) );
			$offset    = (int) self::post( 'offset' );
			$total     = (int) self::post( 'total' );
			if ( strlen( $upload_id ) < 12 || $total <= 0 ) {
				throw new NLWM_Exception( 'Bad upload request.' );
			}
			$ext  = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
			$base = trim( preg_replace( '/[^A-Za-z0-9_-]+/', '-', pathinfo( $original, PATHINFO_FILENAME ) ), '-' );
			if ( ! in_array( $ext, array( NLWM_EXT, 'wpress' ), true ) ) {
				throw new NLWM_Exception( esc_html( 'Choose a NoLimit backup file (.' . NLWM_EXT . ').' ) );
			}
			if ( '' === $base ) {
				$base = 'upload-' . gmdate( 'Ymd-His' );
			}
			$part = NLWM_Util::backups_dir() . DIRECTORY_SEPARATOR . 'upload-' . $upload_id . '.part';
			clearstatcache( true, $part );
			$have = file_exists( $part ) ? filesize( $part ) : 0;

			if ( $offset !== $have ) {
				// Out of sync (retry after a dropped request): tell the browser where to continue.
				wp_send_json_success( array( 'offset' => $have ) );
			}
			// guard() above verified the nonce and the capability.
			$chunk_error = isset( $_FILES['chunk']['error'] ) ? (int) $_FILES['chunk']['error'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$chunk_tmp   = isset( $_FILES['chunk']['tmp_name'] ) ? (string) $_FILES['chunk']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a temporary path made by PHP, checked with is_uploaded_file().
			if ( UPLOAD_ERR_OK !== $chunk_error || '' === $chunk_tmp || ! is_uploaded_file( $chunk_tmp ) ) {
				throw new NLWM_Exception( esc_html( 'The chunk did not arrive (upload error ' . $chunk_error . ').' ) );
			}
			$in  = fopen( $chunk_tmp, 'rb' );
			$out = fopen( $part, 'ab' );
			if ( ! $in || ! $out || false === stream_copy_to_stream( $in, $out ) ) {
				throw new NLWM_Exception( 'Could not save the uploaded chunk. The disk may be full.' );
			}
			fclose( $in );
			fclose( $out );
			clearstatcache( true, $part );
			$have = filesize( $part );

			if ( $have < $total ) {
				wp_send_json_success( array( 'offset' => $have ) );
			}

			$name = $base . '.' . $ext;
			$i    = 1;
			while ( file_exists( NLWM_Util::backup_path( $name ) ) ) {
				$name = $base . '-' . ( ++$i ) . '.' . $ext;
			}
			if ( ! @rename( $part, NLWM_Util::backup_path( $name ) ) ) {
				throw new NLWM_Exception( 'Could not save the uploaded file.' );
			}
			try {
				$info = NLWM_Import::inspect( NLWM_Util::backup_path( $name ) );
			} catch ( Exception $e ) {
				wp_delete_file( NLWM_Util::backup_path( $name ) );
				throw $e;
			}
			NLWM_Backups::set_meta( $name, array(
				'type'     => 'upload',
				'created'  => time(),
				'label'    => 'Uploaded',
				'site_url' => isset( $info['manifest']['home_url'] ) ? $info['manifest']['home_url'] : '',
			) );
			wp_send_json_success( array( 'offset' => $have, 'done' => true, 'name' => $name ) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	/* ================================================================ backups */

	public static function ajax_inspect() {
		self::guard( 'import' );
		try {
			$name = (string) self::post( 'name' );
			$info = NLWM_Import::inspect( NLWM_Util::backup_path( $name ) );
			$m    = $info['manifest'];
			wp_send_json_success( array(
				'name'       => $name,
				'format'     => 'wpress' === $info['format'] ? 'Compatible backup' : 'NoLimit Migration',
				'size'       => NLWM_Util::format_bytes( $info['size'] ),
				'from'       => isset( $m['home_url'] ) ? $m['home_url'] : '',
				'to'         => home_url(),
				'wp_version' => isset( $m['wp_version'] ) ? $m['wp_version'] : '',
				'php'        => isset( $m['php_version'] ) ? $m['php_version'] : '',
				'created'    => ! empty( $m['created'] ) ? wp_date( 'j M Y, H:i', $m['created'] ) : '',
				'site_name'  => isset( $m['site_name'] ) ? $m['site_name'] : '',
				'has_db'     => (bool) $info['db_entry'],
				'files'      => $info['entries'],
				'plugins'    => $info['plugins'],
				'themes'     => $info['themes'],
				'uploads'    => $info['uploads'],
				'theme'      => isset( $m['stylesheet'] ) ? $m['stylesheet'] : '',
				'newer_wp'   => ! empty( $m['wp_version'] ) && version_compare( $m['wp_version'], get_bloginfo( 'version' ), '>' ),
				'encrypted'  => ! empty( $info['encrypted'] ),
			) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function backups_for_ui() {
		$out = array();
		foreach ( NLWM_Backups::all() as $b ) {
			$out[] = array(
				'name'     => $b['name'],
				'label'    => $b['label'],
				'type'     => $b['type'],
				'size'     => NLWM_Util::format_bytes( $b['size'] ),
				'created'  => wp_date( 'j M Y, H:i', $b['created'] ),
				'site'     => isset( $b['site_url'] ) ? $b['site_url'] : '',
				'locked'   => ! empty( $b['locked'] ),
				'download' => NLWM_Remote::download_url( $b['name'], false ),
				'wpress'   => 'wpress' === pathinfo( $b['name'], PATHINFO_EXTENSION ),
			);
		}
		return $out;
	}

	public static function ajax_backups() {
		self::guard();
		wp_send_json_success( self::backups_for_ui() );
	}

	public static function ajax_delete() {
		self::guard( 'import' );
		try {
			NLWM_Backups::delete( (string) self::post( 'name' ) );
			wp_send_json_success( self::backups_for_ui() );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_label() {
		self::guard();
		try {
			$name = (string) self::post( 'name' );
			NLWM_Util::backup_path( $name );
			NLWM_Backups::set_meta( $name, array( 'label' => sanitize_text_field( self::post( 'label' ) ) ) );
			wp_send_json_success( self::backups_for_ui() );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_lock() {
		self::guard();
		try {
			$name = (string) self::post( 'name' );
			NLWM_Util::backup_path( $name );
			NLWM_Backups::set_meta( $name, array( 'locked' => (bool) self::post( 'locked' ) ) );
			wp_send_json_success( self::backups_for_ui() );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_link() {
		self::guard();
		try {
			$name  = (string) self::post( 'name' );
			$hours = max( 1, min( 168, (int) self::post( 'hours', 24 ) ) );
			if ( ! file_exists( NLWM_Util::backup_path( $name ) ) ) {
				throw new NLWM_Exception( 'Backup not found.' );
			}
			wp_send_json_success( array(
				'url'     => NLWM_Remote::download_url( $name, true, $hours ),
				'expires' => wp_date( 'j M Y, H:i', time() + $hours * HOUR_IN_SECONDS ),
			) );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_settings() {
		self::guard( 'manage_options' );
		$allowed = array_values( array_diff( NLWM_Util::skip_keys(), array( 'no_database' ) ) );
		$freq    = sanitize_key( self::post( 'schedule', 'off' ) );
		$time    = preg_match( '/^\d{2}:\d{2}$/', (string) self::post( 'schedule_time' ) ) ? self::post( 'schedule_time' ) : '03:00';
		$s       = array(
			'schedule'      => isset( NLWM_Schedule::frequencies()[ $freq ] ) ? $freq : 'off',
			'schedule_time' => $time,
			'retention'     => max( 1, min( 100, (int) self::post( 'retention', 5 ) ) ),
			'notify_email'  => sanitize_email( self::post( 'notify_email' ) ),
			'notify_on'     => 'always' === self::post( 'notify_on' ) ? 'always' : 'failure',
			'exclude'       => array_values( array_intersect( $allowed, array_map( 'sanitize_key', (array) self::post( 'exclude', array() ) ) ) ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- a plugin setting, not a query argument.
			'exclude_paths' => sanitize_textarea_field( self::post( 'exclude_paths' ) ),
			'max_file_mb'   => absint( self::post( 'max_file_mb' ) ),
		);
		update_option( 'nlwm_settings', $s, false );
		NLWM_Schedule::reschedule( $s );
		$next = NLWM_Schedule::next_run();
		wp_send_json_success( array(
			'message' => 'Settings saved.',
			'next'    => $next ? wp_date( 'j M Y, H:i', $next ) : '',
		) );
	}

	/* ================================================================ cloud storage */

	public static function ajax_storage_save() {
		self::guard( 'manage_options' );
		$s                    = NLWM_Util::settings();
		$storage              = sanitize_key( self::post( 'storage', 'local' ) );
		$s['storage']         = in_array( $storage, array( 'local', 'both', 'remote' ), true ) ? $storage : 'local';
		$s['drive_retention'] = max( 1, min( 100, (int) self::post( 'drive_retention', 10 ) ) );
		update_option( 'nlwm_settings', $s, false );
		wp_send_json_success( array( 'message' => 'Saved.' ) );
	}

	/** Connected cloud destinations for the UI. */
	public static function storages_for_ui() {
		$out = array();
		foreach ( NLWM_Storages::connected() as $st ) {
			$out[] = array( 'id' => $st->id(), 'label' => $st->label(), 'account' => $st->account() );
		}
		return $out;
	}

	public static function ajax_remote_files() {
		self::guard();
		try {
			$storage = NLWM_Storages::get( sanitize_key( self::post( 'storage' ) ) );
			$local   = array();
			foreach ( NLWM_Backups::all() as $b ) {
				$local[ $b['name'] ] = true;
			}
			$out = array();
			foreach ( $storage->files() as $f ) {
				$out[] = array(
					'id'      => $f['id'],
					'name'    => $f['name'],
					'label'   => $f['label'],
					'type'    => $f['type'],
					'size'    => NLWM_Util::format_bytes( $f['size'] ),
					'created' => wp_date( 'j M Y, H:i', $f['created'] ),
					'local'   => isset( $local[ $f['name'] ] ),
					'view'    => isset( $f['view'] ) ? $f['view'] : '',
				);
			}
			wp_send_json_success( $out );
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_remote_delete() {
		self::guard( 'import' );
		try {
			NLWM_Storages::get( sanitize_key( self::post( 'storage' ) ) )->delete( preg_replace( '#[^A-Za-z0-9_./=+-]#', '', (string) self::post( 'id' ) ) );
			wp_send_json_success();
		} catch ( Exception $e ) {
			self::fail( $e );
		}
	}

	/* ================================================================ maintenance */

	protected static function maintenance_file() {
		return NLWM_Util::backups_dir() . DIRECTORY_SEPARATOR . '.maintenance';
	}

	public static function maintenance( $on ) {
		if ( $on ) {
			@file_put_contents( self::maintenance_file(), (string) time() );
		} else {
			wp_delete_file( self::maintenance_file() );
		}
	}

	/** Visitors see a 503 page while a restore runs. Admin, AJAX, cron and CLI are untouched. */
	public static function maintenance_gate() {
		$file = self::maintenance_file();
		if ( ! file_exists( $file ) ) {
			return;
		}
		if ( time() - (int) @file_get_contents( $file ) > HOUR_IN_SECONDS ) {
			wp_delete_file( $file ); // stale flag from a crashed restore
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'export' ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false !== strpos( $uri, 'wp-login.php' ) ) {
			return;
		}
		header( 'Retry-After: 300' );
		nocache_headers();
		// WordPress's own error page (styled by core), with a 503 so search engines come back later.
		wp_die(
			'<h1>' . esc_html__( 'We&rsquo;ll be right back', 'nolimit-migration' ) . '</h1><p>' . esc_html__( 'This site is being updated. Please check again in a few minutes.', 'nolimit-migration' ) . '</p>',
			esc_html__( 'Back soon', 'nolimit-migration' ),
			array( 'response' => 503 )
		);
	}
}
