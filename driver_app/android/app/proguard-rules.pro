# Flutter's own engine classes are referenced reflectively by the embedding.
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }

# Line numbers are what make an obfuscated crash report readable.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile
