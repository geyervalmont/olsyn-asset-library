using System.Windows;
using System.Windows.Media;

namespace Opal.Revit;

internal enum RibbonIcon
{
    Apply,
    Audit,
    Settings,
}

/// <summary>
/// Small vector icons kept in the assembly so every supported Revit build gets
/// the same artwork without file-path or DPI dependencies.
/// </summary>
internal static class RibbonIcons
{
    private static readonly Brush Ink = FrozenBrush(Color.FromRgb(42, 52, 57));
    private static readonly Brush Accent = FrozenBrush(Color.FromRgb(32, 99, 103));
    private static readonly Brush Material = FrozenBrush(Color.FromRgb(221, 207, 178));

    public static ImageSource Create(RibbonIcon icon)
    {
        var drawing = new DrawingGroup();
        using (var context = drawing.Open())
        {
            // Establish a stable 32 × 32 viewbox without drawing a background.
            context.DrawRectangle(Brushes.Transparent, null, new Rect(0, 0, 32, 32));

            switch (icon)
            {
                case RibbonIcon.Apply:
                    DrawApply(context);
                    break;
                case RibbonIcon.Audit:
                    DrawAudit(context);
                    break;
                case RibbonIcon.Settings:
                    DrawSettings(context);
                    break;
            }
        }

        drawing.Freeze();
        var image = new DrawingImage(drawing);
        image.Freeze();
        return image;
    }

    private static void DrawApply(DrawingContext context)
    {
        context.DrawRoundedRectangle(Material, Pen(Ink, 1.7), new Rect(3.5, 4.5, 17, 20), 2.5, 2.5);
        context.DrawGeometry(null, Pen(Ink, 1.35), Geometry.Parse("M 4.5,19 L 19.5,6 M 8,24 L 20,13"));
        context.DrawEllipse(Accent, null, new Point(8.5, 9.5), 2.25, 2.25);
        context.DrawGeometry(null, Pen(Accent, 2.4), Geometry.Parse("M 16,25.5 L 28,25.5 M 23,20.5 L 28,25.5 L 23,30.5"));
    }

    private static void DrawAudit(DrawingContext context)
    {
        context.DrawGeometry(null, Pen(Ink, 2), Geometry.Parse("M 25.5,11 A 10.5,10.5 0 0 0 8.5,7.5 L 5,11 M 8.5,7.5 L 9.5,3.5"));
        context.DrawGeometry(null, Pen(Ink, 2), Geometry.Parse("M 6.5,21 A 10.5,10.5 0 0 0 23.5,24.5 L 27,21 M 23.5,24.5 L 22.5,28.5"));
        context.DrawGeometry(null, Pen(Accent, 2.5), Geometry.Parse("M 10.5,16 L 14.5,20 L 22,12"));
    }

    private static void DrawSettings(DrawingContext context)
    {
        var pen = Pen(Ink, 1.8);
        context.DrawLine(pen, new Point(4, 8), new Point(28, 8));
        context.DrawLine(pen, new Point(4, 16), new Point(28, 16));
        context.DrawLine(pen, new Point(4, 24), new Point(28, 24));
        context.DrawEllipse(Accent, Pen(Brushes.White, 1.25), new Point(11, 8), 3.2, 3.2);
        context.DrawEllipse(Accent, Pen(Brushes.White, 1.25), new Point(22, 16), 3.2, 3.2);
        context.DrawEllipse(Accent, Pen(Brushes.White, 1.25), new Point(15, 24), 3.2, 3.2);
    }

    private static Pen Pen(Brush brush, double thickness)
    {
        var pen = new Pen(brush, thickness)
        {
            StartLineCap = PenLineCap.Round,
            EndLineCap = PenLineCap.Round,
            LineJoin = PenLineJoin.Round,
        };
        pen.Freeze();
        return pen;
    }

    private static Brush FrozenBrush(Color colour)
    {
        var brush = new SolidColorBrush(colour);
        brush.Freeze();
        return brush;
    }
}
